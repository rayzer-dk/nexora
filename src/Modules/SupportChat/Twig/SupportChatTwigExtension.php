<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Twig;

use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\SupportChat\Application\SupportChatSettings;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SupportChatTwigExtension extends AbstractExtension
{
    /** @var array<string,mixed>|null|false */
    private array|null|false $memo = false;

    public function __construct(private readonly SupportChatSettings $settings, private readonly StorefrontContextResolver $contexts, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('support_chat', $this->support(...))];
    }

    /** @return array{site_chat:bool,direct_url:string,welcome:string,offline:string}|null */
    public function support(): ?array
    {
        if ($this->memo !== false) {
            return $this->memo;
        }
        $request = $this->requests->getCurrentRequest();
        $this->memo = null;
        if ($request === null) {
            return null;
        }
        try {
            $storeId = $this->contexts->resolve($request)->storeId;
            if (!$this->settings->isReady($storeId)) {
                return null;
            }
            $s = $this->settings->get($storeId);
        } catch (\Throwable) {
            return null;
        }
        $direct = $s['direct_enabled'] && $s['bot_username'] !== '' ? 'https://t.me/' . $s['bot_username'] : '';
        if (!$s['site_chat_enabled'] && $direct === '') {
            return null;
        }

        return $this->memo = ['site_chat' => $s['site_chat_enabled'], 'direct_url' => $direct, 'welcome' => $s['welcome_text'], 'offline' => $s['offline_text']];
    }
}
