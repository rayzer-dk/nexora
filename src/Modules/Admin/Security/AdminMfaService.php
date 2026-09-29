<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Security;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/** Stores and verifies administrator two-factor credentials. Secrets are encrypted at rest (libsodium secretbox). */
final class AdminMfaService
{
    private const RECOVERY_CODES = 8;

    public function __construct(private readonly Connection $connection, private readonly SecretVault $vault, private readonly string $appSecret)
    {
    }

    public function isEnabled(int $adminId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM mc_admin_mfa WHERE admin_id=? AND enabled_at IS NOT NULL', [$adminId]);
    }

    /** Pending (not yet confirmed) secret, created on demand. */
    public function pendingSecret(int $adminId): string
    {
        $row = $this->row($adminId);
        if ($row !== null && $row['enabled_at'] === null) {
            return $this->decrypt($adminId, (string) $row['secret_encrypted']);
        }
        if ($row !== null) {
            throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.mfa_already_enabled'));
        }
        $secret = Totp::generateSecret();
        $now = $this->now();
        $this->connection->insert('mc_admin_mfa', [
            'admin_id' => $adminId, 'secret_encrypted' => $this->encrypt($adminId, $secret), 'enabled_at' => null,
            'recovery_hashes_json' => '[]', 'last_used_step' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $secret;
    }

    public function cancelPending(int $adminId): void
    {
        $this->connection->executeStatement('DELETE FROM mc_admin_mfa WHERE admin_id=? AND enabled_at IS NULL', [$adminId]);
    }

    /** @return list<string>|null plaintext recovery codes (shown once) or null when the code is wrong */
    public function confirm(int $adminId, string $code): ?array
    {
        $row = $this->row($adminId);
        if ($row === null || $row['enabled_at'] !== null) {
            return null;
        }
        $step = Totp::verify($this->decrypt($adminId, (string) $row['secret_encrypted']), $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $now = $this->now();
        $this->connection->update('mc_admin_mfa', [
            'enabled_at' => $now, 'last_used_step' => $step,
            'recovery_hashes_json' => json_encode(array_map($this->hashRecovery(...), $codes), JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ], ['admin_id' => $adminId]);
        return $codes;
    }

    /** Verifies a TOTP code (replay-protected) or a one-time recovery code. */
    public function verifyLogin(int $adminId, string $input): bool
    {
        $row = $this->row($adminId);
        if ($row === null || $row['enabled_at'] === null) {
            return true;
        }
        $input = trim($input);
        $step = Totp::verify($this->decrypt($adminId, (string) $row['secret_encrypted']), $input);
        if ($step !== null) {
            // Atomic replay guard: the step must be strictly newer than the last accepted one.
            return $this->connection->executeStatement(
                'UPDATE mc_admin_mfa SET last_used_step=?, updated_at=? WHERE admin_id=? AND last_used_step < ?',
                [$step, $this->now(), $adminId, $step],
            ) === 1;
        }
        return $this->consumeRecoveryCode($adminId, $input);
    }

    /** @return list<string>|null */
    public function regenerateRecoveryCodes(int $adminId, string $code): ?array
    {
        if (!$this->isEnabled($adminId) || !$this->verifyLogin($adminId, $code) || $this->looksLikeRecovery($code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $this->connection->update('mc_admin_mfa', [
            'recovery_hashes_json' => json_encode(array_map($this->hashRecovery(...), $codes), JSON_THROW_ON_ERROR),
            'updated_at' => $this->now(),
        ], ['admin_id' => $adminId]);
        return $codes;
    }

    public function disable(int $adminId, string $code): bool
    {
        if (!$this->isEnabled($adminId) || !$this->verifyLogin($adminId, $code)) {
            return false;
        }
        $this->connection->delete('mc_admin_mfa', ['admin_id' => $adminId]);
        return true;
    }

    public function remainingRecoveryCodes(int $adminId): int
    {
        $row = $this->row($adminId);
        $list = $row === null ? [] : json_decode((string) $row['recovery_hashes_json'], true);
        return is_array($list) ? count($list) : 0;
    }

    private function consumeRecoveryCode(int $adminId, string $input): bool
    {
        if (!$this->looksLikeRecovery($input)) {
            return false;
        }
        return (bool) $this->connection->transactional(function (Connection $db) use ($adminId, $input): bool {
            $json = $db->fetchOne('SELECT recovery_hashes_json FROM mc_admin_mfa WHERE admin_id=? FOR UPDATE', [$adminId]);
            $hashes = is_string($json) ? json_decode($json, true) : [];
            if (!is_array($hashes)) {
                return false;
            }
            $candidate = $this->hashRecovery($this->normalizeRecovery($input));
            foreach ($hashes as $index => $hash) {
                if (is_string($hash) && hash_equals($hash, $candidate)) {
                    unset($hashes[$index]);
                    $db->update('mc_admin_mfa', ['recovery_hashes_json' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR), 'updated_at' => $this->now()], ['admin_id' => $adminId]);
                    return true;
                }
            }
            return false;
        });
    }

    private function looksLikeRecovery(string $input): bool
    {
        return preg_match('/^[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}$/i', trim($input)) === 1;
    }

    private function normalizeRecovery(string $input): string
    {
        $plain = strtolower(str_replace('-', '', trim($input)));
        return substr($plain, 0, 4) . '-' . substr($plain, 4, 4) . '-' . substr($plain, 8, 4);
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $hex = bin2hex(random_bytes(6));
            $codes[] = substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8, 4);
        }
        return $codes;
    }

    private function hashRecovery(string $code): string
    {
        return sodium_bin2hex(sodium_crypto_generichash($this->normalizeRecovery($code), sodium_crypto_generichash('nexora-admin-mfa|' . $this->appSecret, '', SODIUM_CRYPTO_GENERICHASH_KEYBYTES), 32));
    }

    private function encrypt(int $adminId, string $plain): string
    {
        return $this->vault->encrypt($plain, 'admin_mfa:' . $adminId);
    }

    private function decrypt(int $adminId, string $blob): string
    {
        return $this->vault->decrypt($blob, 'admin_mfa:' . $adminId);
    }

    /** @return array<string,mixed>|null */
    private function row(int $adminId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM mc_admin_mfa WHERE admin_id=?', [$adminId]);
        return is_array($row) ? $row : null;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
