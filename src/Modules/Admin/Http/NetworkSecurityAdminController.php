<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Commerce\Modules\Security\Network\NetworkSecurityChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A read-only report: transport security of this site and the DNS records that protect the shop's e-mail. */
final class NetworkSecurityAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly NetworkSecurityChecker $checker,
        private readonly NotificationChannelSettings $channels,
        #[Autowire('%commerce.mail.from_address%')] private readonly string $defaultFrom,
    ) {
    }

    #[Route('/admin/system/network', name: 'admin_system_network', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $from = (string) ($this->channels->get($context->storeId)['from_address'] ?? '');
        $from = $from !== '' ? $from : $this->defaultFrom;
        $domain = str_contains($from, '@') ? substr(strrchr($from, '@') ?: '', 1) : '';
        $selector = (string) $request->query->get('selector', '');

        return $this->render('@storefront/admin/system/network.html.twig', [
            'transport' => $this->checker->transport($request),
            'mail' => $this->checker->mail($domain, $selector),
            'mail_domain' => $domain,
            'selector' => $selector,
        ]);
    }
}
