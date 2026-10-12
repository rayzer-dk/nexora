<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Integration\Application\IntegrationCatalog;
use Commerce\Modules\Integration\Application\IntegrationSecretStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → System → Connections: keys and switches of payment services (and later CRM, marketplaces, messengers) without editing the .env file. */
final class ConnectionsAdminController extends AbstractController
{
    public function __construct(
        private readonly IntegrationSecretStore $store,
        private readonly AdminContextResolver $contexts,
        private readonly \Commerce\Modules\Notification\Messenger\MessengerSettings $messengers,
        private readonly \Commerce\Modules\Notification\Messenger\TelegramCustomerSender $telegram,
        private readonly \Commerce\Modules\Notification\Messenger\ViberCustomerSender $viber,
        private readonly \Commerce\Modules\Marketplace\Application\MarketplaceSyncService $marketplaces,
        private readonly \Doctrine\DBAL\Connection $db,
    ) {
    }

    #[Route('/admin/system/integrations/connections/{group}', name: 'admin_system_integrations_connections', methods: ['GET'], requirements: ['group' => '[a-z_]+'], defaults: ['group' => 'payments'])]
    public function index(string $group, Request $request): Response
    {
        $this->contexts->resolve($request);
        $groups = IntegrationCatalog::groups();
        if (!isset($groups[$group])) {
            throw $this->createNotFoundException();
        }
        $stored = $this->store->read();
        $providers = [];
        foreach ($groups[$group] as $code => $provider) {
            $fields = [];
            $missing = 0;
            $enabledKey = null;
            foreach ($provider['fields'] as $name => $def) {
                $value = IntegrationSecretStore::effective($name);
                $required = !isset($def['default']) && in_array($def['type'], ['secret', 'text'], true) && empty($def['optional']);
                if ($required && $value === '') {
                    ++$missing;
                }
                if (str_ends_with($name, '_ENABLED')) {
                    $enabledKey = $name;
                }
                $fields[] = [
                    'name' => $name, 'type' => $def['type'], 'options' => $def['options'] ?? [], 'default' => $def['default'] ?? '',
                    'value' => $def['type'] === 'secret' ? '' : ($value !== '' ? $value : ($def['default'] ?? '')),
                    'set' => $value !== '', 'stored' => ($stored[$name] ?? '') !== '', 'required' => $required,
                ];
            }
            $enabled = $enabledKey === null ? true : IntegrationSecretStore::effective($enabledKey) === '1';
            $providers[] = ['code' => $code, 'label' => $provider['label'], 'docs' => $provider['docs'], 'fields' => $fields, 'enabled' => $enabled, 'missing' => $missing,
                'state' => !$enabled ? 'off' : ($missing > 0 ? 'incomplete' : 'on')];
        }

        $sync = null;
        if ($group === 'marketplaces') {
            $sync = [
                'state' => $this->db->fetchAllAssociative('SELECT channel,last_run_at,imported_total,last_error FROM mc_marketplace_state ORDER BY channel'),
                'log' => $this->db->fetchAllAssociative('SELECT channel,level,message,created_at FROM mc_marketplace_log ORDER BY id DESC LIMIT 20'),
            ];
        }

        return $this->render('@storefront/admin/system/connections.html.twig', ['group' => $group, 'groups' => array_keys($groups), 'providers' => $providers, 'sync' => $sync]);
    }

    /** Runs one marketplace pass at once (the scheduler does it every 5 minutes). */
    #[Route('/admin/system/integrations/connections/marketplaces/run', name: 'admin_system_integrations_marketplace_run', methods: ['POST'], priority: 10)]
    public function runMarketplaces(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('connections_marketplaces_run', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $result = $this->marketplaces->run();
            $orders = array_sum(array_column($result, 'orders'));
            $stock = array_sum(array_column($result, 'stock'));
            $errors = array_sum(array_column($result, 'errors'));
            $this->addFlash($result === [] ? 'error' : ($errors > 0 ? 'error' : 'success'), $result === [] ? CanonicalUiText::get('admin.conn.mp_none') : CanonicalUiText::get($errors > 0 ? 'admin.conn.mp_errors' : 'admin.conn.mp_done', ['orders' => (string) $orders, 'stock' => (string) $stock, 'errors' => (string) $errors]));
        } catch (\Throwable $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.conn.mp_errors', ['orders' => '0', 'stock' => '0', 'errors' => '1']));
        }

        return $this->redirectToRoute('admin_system_integrations_connections', ['group' => 'marketplaces']);
    }

    /** Tells Telegram and Viber where to send what customers write to the shop's bot. */
    #[Route('/admin/system/integrations/connections/messengers/webhooks', name: 'admin_system_integrations_messenger_webhooks', methods: ['POST'], priority: 10)]
    public function registerWebhooks(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('connections_messengers', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $base = rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
        $done = [];
        try {
            if ($this->messengers->telegramReady()) {
                $this->telegram->call('setWebhook', ['url' => $base . '/webhooks/messengers/telegram/' . $this->messengers->webhookSecret(), 'allowed_updates' => ['message']]);
                $done[] = 'Telegram';
            }
            if ($this->messengers->viberReady()) {
                $this->viber->call('set_webhook', ['url' => $base . '/webhooks/messengers/viber', 'event_types' => ['conversation_started', 'unsubscribed', 'subscribed']]);
                $done[] = 'Viber';
            }
            $this->addFlash($done === [] ? 'error' : 'success', $done === [] ? CanonicalUiText::get('admin.conn.webhook_none') : CanonicalUiText::get('admin.conn.webhook_done', ['channels' => implode(', ', $done)]));
        } catch (\Throwable $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.conn.webhook_failed', ['detail' => mb_substr($e->getMessage(), 0, 200)]));
        }

        return $this->redirectToRoute('admin_system_integrations_connections', ['group' => 'messengers']);
    }

    #[Route('/admin/system/integrations/connections/{group}/save', name: 'admin_system_integrations_connections_save', methods: ['POST'], requirements: ['group' => '[a-z_]+'])]
    public function save(string $group, Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        if (!isset(IntegrationCatalog::groups()[$group])) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('connections_' . $group, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $provider = (string) $request->request->get('provider', '');
        $allowed = IntegrationCatalog::fieldsOf($group, $provider);
        $values = array_intersect_key($request->request->all('key'), $allowed);
        $clear = array_values(array_filter(array_map('strval', array_keys($request->request->all('clear'))), static fn (string $name): bool => isset($allowed[$name])));
        try {
            $this->store->save($values, $clear);
            $this->addFlash('success', CanonicalUiText::get('admin.conn.saved'));
        } catch (\Throwable) {
            $this->addFlash('error', CanonicalUiText::get('admin.carriers.save_failed'));
        }

        return $this->redirectToRoute('admin_system_integrations_connections', ['group' => $group]);
    }
}
