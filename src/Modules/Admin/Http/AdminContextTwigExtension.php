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
        private readonly \Commerce\Core\Update\PendingMigrations $migrations,
        private readonly \Commerce\Modules\Admin\Application\AdminQuickLinks $quickLinks,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_context_switcher', [$this, 'contextSwitcher']), new TwigFunction('admin_undo_pending', [$this, 'undoPending']), new TwigFunction('admin_pending_migrations', [$this, 'pendingMigrations']), new TwigFunction('admin_attention', [$this, 'attention']), new TwigFunction('admin_quick_links', [$this, 'quickLinks'])];
    }

    /** @return list<array{label:string,href:string,icon:string}> */
    public function quickLinks(): array
    {
        $user = $this->security->getUser();

        return $user instanceof AdminUser ? $this->quickLinks->forAdmin($user->id) : [];
    }

    /**
     * New items that wait for a person: return requests, reviews, product questions and withdrawal notices (the header counter).
     *
     * @return array{returns:int,reviews:int,questions:int,withdrawals:int,stock:int,total:int}
     */
    public function attention(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $zero = ['returns' => 0, 'reviews' => 0, 'questions' => 0, 'withdrawals' => 0, 'stock' => 0, 'total' => 0, 'forum' => 0];
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !$this->security->getUser() instanceof AdminUser) {
            return $zero;
        }
        try {
            $storeId = $this->contexts->resolve($request)->storeId;
        } catch (\Throwable) {
            return $zero;
        }
        $count = function (string $sql) use ($storeId): int {
            try {
                return (int) $this->db->fetchOne($sql, [$storeId]);
            } catch (\Throwable) {
                return 0;
            }
        };
        $out = [
            'returns' => $count("SELECT COUNT(*) FROM mc_return_request WHERE store_id=? AND status='requested'"),
            'reviews' => $count("SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND status='pending'"),
            'questions' => $count("SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND status='pending'"),
            'withdrawals' => $count('SELECT COUNT(*) FROM mc_withdrawal_notice WHERE store_id=? AND acknowledged_at IS NULL'),
            'stock' => $count("SELECT COUNT(*) FROM mc_stock_notification_request WHERE store_id=? AND status='active' AND admin_seen_at IS NULL"),
        ];
        $out['total'] = array_sum($out);
        // The forum has its own bell: open reports and everything waiting for approval.
        try {
            $out['forum'] = (int) $this->db->fetchOne("SELECT (SELECT COUNT(*) FROM mc_forum_report WHERE store_id=? AND status='open') + (SELECT COUNT(*) FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.status='pending') + (SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.status='pending' AND t.status='published')", [$storeId, $storeId, $storeId]);
        } catch (\Throwable) {
            $out['forum'] = 0;
        }

        return $cache = $out;
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
    /** Number of database changes that are not applied yet, for administrators who may run the update; 0 otherwise. */
    public function pendingMigrations(): int
    {
        $request = $this->requests->getCurrentRequest();
        $user = $this->security->getUser();
        if ($request === null || !$user instanceof AdminUser || !$request->isMethod('GET') || $request->attributes->get('_route') === 'admin_system_update') {
            return 0;
        }

        return count($this->migrations->pending());
    }

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
