<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Security\AdminMfaService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "First steps" wizard for a fresh installation. Progress is derived from real data (never stored),
 * so it can not drift: a step is done exactly when the shop has what the step asks for.
 */
final class OnboardingAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
        private readonly PaymentProviderRegistry $payments,
        private readonly AdminMfaService $mfa,
    ) {
    }

    #[Route('/admin/onboarding', name: 'admin_onboarding', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $steps = $this->steps($this->contexts->resolve($request)->storeId, $user instanceof AdminUser ? $user->id : 0);
        $done = count(array_filter($steps, static fn (array $s): bool => $s['done']));

        return $this->render('@storefront/admin/onboarding.html.twig', [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'percent' => (int) round($done * 100 / max(1, count($steps))),
        ]);
    }

    /** @return list<array{key:string,icon:string,done:bool,url:string}> */
    public function steps(int $storeId, int $adminId): array
    {
        $count = fn (string $sql, array $params = []): int => $this->scalar($sql, $params);

        return [
            ['key' => 'products', 'icon' => 'package', 'url' => '/admin/catalog/products', 'done' => $count("SELECT COUNT(*) FROM mc_store_product sp JOIN mc_product p ON p.id=sp.product_id WHERE sp.store_id=? AND p.status='published'", [$storeId]) > 0],
            ['key' => 'shipping', 'icon' => 'truck', 'url' => '/admin/shipments/countries', 'done' => $count('SELECT COUNT(*) FROM mc_shipping_country WHERE store_id=? AND enabled=1', [$storeId]) > 0],
            ['key' => 'payment', 'icon' => 'wallet', 'url' => '/admin/system/integrations', 'done' => count($this->payments->enabledMethods()) > 0],
            ['key' => 'taxes', 'icon' => 'receipt', 'url' => '/admin/system/store', 'done' => $count('SELECT COUNT(*) FROM mc_tax_rate WHERE enabled=1') > 0 || $count('SELECT COUNT(*) FROM mc_store_tax_registration WHERE store_id=?', [$storeId]) > 0],
            ['key' => 'legal', 'icon' => 'file-text', 'url' => '/admin/content/pages', 'done' => $count("SELECT COUNT(*) FROM mc_content_entry WHERE store_id=? AND system_key IS NOT NULL AND status='published'", [$storeId]) >= 3],
            ['key' => 'security', 'icon' => 'shield-check', 'url' => '/admin/account/security', 'done' => $adminId > 0 && $this->mfa->isEnabled($adminId)],
            ['key' => 'order', 'icon' => 'shopping-bag', 'url' => '/admin/orders', 'done' => $count('SELECT COUNT(*) FROM mc_sales_order WHERE store_id=?', [$storeId]) > 0],
        ];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        try {
            return (int) $this->db->fetchOne($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }
}
