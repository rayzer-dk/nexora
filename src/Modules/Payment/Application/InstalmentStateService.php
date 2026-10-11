<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Modules\Payment\Contract\PollablePaymentProviderInterface;
use Commerce\Modules\Payment\Provider\MonoParts\MonoPartsPaymentProvider;
use Doctrine\DBAL\Connection;

/** Brings the state of an instalment payment (monobank, PrivatBank) from the bank into the order: on the bank's callback and when the shopper comes back. */
final readonly class InstalmentStateService
{
    public function __construct(private Connection $db, private PaymentProviderRegistry $providers, private PaymentLifecycleService $lifecycle)
    {
    }

    /** Asks the bank for the current state and applies it. Returns the status applied, or null when the bank gave none. */
    public function sync(string $providerCode, string $providerReference): ?string
    {
        $provider = $this->providers->require($providerCode);
        if (!$provider instanceof PollablePaymentProviderInterface) {
            return null;
        }
        $state = $provider->pollState($providerReference);
        if ($state === null) {
            return null;
        }

        return $this->apply($providerCode, $providerReference, $state['status'], $state['payload']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function apply(string $providerCode, string $providerReference, string $status, array $payload): string
    {
        $provider = $this->providers->require($providerCode);
        // monobank: the shopper agreed in the app; with auto-confirm the shop confirms to the bank at once and the instalment becomes active.
        if ($provider instanceof MonoPartsPaymentProvider && $status === 'hold' && $provider->autoConfirm()) {
            $provider->client()->confirm($providerReference);
            $after = $provider->pollState($providerReference);
            if ($after !== null) {
                $status = $after['status'];
                $payload = $after['payload'];
            }
        }
        $payment = $this->db->fetchAssociative(
            'SELECT amount_minor,currency,status FROM mc_payment WHERE provider_code=? AND provider_reference=? ORDER BY id DESC LIMIT 1',
            [$providerCode, $providerReference],
        );
        if (!is_array($payment)) {
            throw new \RuntimeException('payment not found for the instalment');
        }
        $failure = $status === 'failure' ? (string) ($payload['order_sub_state'] ?? $payload['message'] ?? $payload['paymentState'] ?? '') : null;
        $this->lifecycle->applyProviderStatus($providerCode, $providerReference, $status, null, (int) $payment['amount_minor'], (string) $payment['currency'], $failure === '' ? null : $failure, $payload);

        return $status;
    }
}
