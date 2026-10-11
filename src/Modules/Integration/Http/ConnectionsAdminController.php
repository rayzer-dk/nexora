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
    public function __construct(private readonly IntegrationSecretStore $store, private readonly AdminContextResolver $contexts)
    {
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

        return $this->render('@storefront/admin/system/connections.html.twig', ['group' => $group, 'groups' => array_keys($groups), 'providers' => $providers]);
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
