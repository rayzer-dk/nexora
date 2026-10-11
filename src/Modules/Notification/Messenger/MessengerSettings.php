<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Messenger;

/** Telegram and Viber accounts of the shop that talk to customers (not the alerts to the owner). Values come from the Connections page or the .env file. */
final readonly class MessengerSettings
{
    public function __construct(
        private bool $tgEnabled,
        private string $tgToken,
        private string $tgUsername,
        private string $tgApiBase,
        private bool $viberEnabled,
        private string $viberToken,
        private string $viberName,
        private string $viberUri,
        private string $viberApiBase,
    ) {
    }

    public function telegramReady(): bool
    {
        return $this->tgEnabled && $this->tgToken !== '' && $this->tgUsername !== '';
    }

    public function viberReady(): bool
    {
        return $this->viberEnabled && $this->viberToken !== '' && $this->viberUri !== '';
    }

    public function anyReady(): bool
    {
        return $this->telegramReady() || $this->viberReady();
    }

    public function telegramToken(): string
    {
        return $this->tgToken;
    }

    public function telegramApiBase(): string
    {
        return rtrim($this->tgApiBase, '/');
    }

    public function telegramUsername(): string
    {
        return ltrim($this->tgUsername, '@');
    }

    /** The secret part of the Telegram webhook address, derived from the bot token so it needs no setting of its own. */
    public function webhookSecret(): string
    {
        return substr(hash('sha256', 'nexora-telegram-webhook|' . $this->tgToken), 0, 32);
    }

    public function viberToken(): string
    {
        return $this->viberToken;
    }

    public function viberName(): string
    {
        return $this->viberName !== '' ? mb_substr($this->viberName, 0, 28) : 'Shop';
    }

    public function viberUri(): string
    {
        return $this->viberUri;
    }

    public function viberApiBase(): string
    {
        return rtrim($this->viberApiBase, '/');
    }

    public function deepLink(string $channel, string $token): string
    {
        return $channel === 'telegram'
            ? 'https://t.me/' . rawurlencode($this->telegramUsername()) . '?start=' . rawurlencode($token)
            : 'viber://pa?chatURI=' . rawurlencode($this->viberUri) . '&context=' . rawurlencode($token);
    }
}
