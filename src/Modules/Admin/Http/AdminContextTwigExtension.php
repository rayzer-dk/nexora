<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminContextTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminContextResolver $contexts,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly \Commerce\Modules\Admin\Undo\AdminUndoService $undo,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_context_switcher', [$this, 'contextSwitcher']), new TwigFunction('admin_undo_pending', [$this, 'undoPending'])];
    }

    /** @return array<string,mixed> */
    public function contextSwitcher(): array
    {
        $request = $this->requests->getCurrentRequest();
        $user = $this->security->getUser();
        if ($request === null || !$user instanceof AdminUser) {
            return ['current' => null, 'stores' => [], 'markets' => [], 'locales' => [], 'currencies' => []];
        }

        try {
            $ctx = $this->contexts->resolve($request);
            $isSuper = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
            $stores = $isSuper
                ? $this->db->fetchAllAssociative("SELECT id,code,name FROM mc_store WHERE status='active' ORDER BY name,id")
                : $this->db->fetchAllAssociative("SELECT s.id,s.code,s.name FROM mc_store s JOIN mc_admin_store_scope sc ON sc.store_id=s.id AND sc.admin_user_id=? WHERE s.status='active' ORDER BY s.name,s.id", [$user->id]);
            $markets = $this->db->fetchAllAssociative("SELECT id,code,name,default_locale,default_currency FROM mc_market WHERE store_id=? AND status='active' ORDER BY name,id", [$ctx->storeId]);
            $locales = $this->db->fetchAllAssociative('SELECT locale_code,is_default FROM mc_store_locale WHERE store_id=? AND enabled=1 ORDER BY is_default DESC,locale_code', [$ctx->storeId]);
            $currencies = $this->db->fetchAllAssociative('SELECT currency_code,is_default FROM mc_store_currency WHERE store_id=? AND enabled=1 ORDER BY is_default DESC,currency_code', [$ctx->storeId]);

            return [
                'current' => ['store_id' => $ctx->storeId, 'market_id' => $ctx->marketId, 'locale' => $ctx->locale, 'currency' => $ctx->currency],
                'stores' => $stores,
                'markets' => $markets,
                'locales' => $locales,
                'currencies' => $currencies,
            ];
        } catch (\Throwable) {
            return ['current' => null, 'stores' => [], 'markets' => [], 'locales' => [], 'currencies' => []];
        }
    }

    /** @return array{id:int,kind:string,label:string}|null the administrator's own last reversible action from the last few minutes */
    public function undoPending(): ?array
    {
        $request = $this->requests->getCurrentRequest();
        $user = $this->security->getUser();
        if ($request === null || !$user instanceof AdminUser || !$request->isMethod('GET')) {
            return null;
        }
        try {
            return $this->undo->latest($this->contexts->resolve($request)->storeId, $user->id, 300);
        } catch (\Throwable) {
            return null;
        }
    }
}
