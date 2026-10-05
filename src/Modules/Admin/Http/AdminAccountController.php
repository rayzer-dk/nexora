<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Self-service for the signed-in administrator: display name and password. Two-factor lives next door. */
final class AdminAccountController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly UserPasswordHasherInterface $passwords, private readonly \Commerce\Modules\Admin\Application\AdminQuickLinks $quickLinks)
    {
    }

    #[Route('/admin/account', name: 'admin_account', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@storefront/admin/account.html.twig', ['admin' => $user]);
    }

    #[Route('/admin/account/quick-links', name: 'admin_account_quick_links', methods: ['GET'])]
    public function quickLinksPage(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@storefront/admin/quick_links.html.twig', ['links' => $this->quickLinks->forAdmin($user->id), 'max' => \Commerce\Modules\Admin\Application\AdminQuickLinks::MAX]);
    }

    #[Route('/admin/account/quick-links/add', name: 'admin_account_quick_links_add', methods: ['POST'])]
    public function quickLinksAdd(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser || !$this->isCsrfTokenValid('admin_quick_links', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->quickLinks->add($user->id, (string) $request->request->get('label', ''), (string) $request->request->get('href', ''), (string) $request->request->get('icon', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.quicklinks.added'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_account_quick_links');
    }

    #[Route('/admin/account/quick-links/{index}/remove', name: 'admin_account_quick_links_remove', methods: ['POST'], requirements: ['index' => '\d+'])]
    public function quickLinksRemove(int $index, Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser || !$this->isCsrfTokenValid('admin_quick_links', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->quickLinks->remove($user->id, $index);

        return $this->redirectToRoute('admin_account_quick_links');
    }

    #[Route('/admin/account/profile', name: 'admin_account_profile', methods: ['POST'])]
    public function profile(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser || !$this->isCsrfTokenValid('admin_account_profile', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.account.error_csrf'));

            return $this->redirectToRoute('admin_account');
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->request->get('display_name', '')) ?? '');
        if ($name === '' || mb_strlen($name) > 190) {
            $this->addFlash('error', CanonicalUiText::get('admin.account.name_invalid'));

            return $this->redirectToRoute('admin_account');
        }
        $this->db->update('mc_admin_user', ['display_name' => $name], ['id' => $user->id]);
        $this->addFlash('success', CanonicalUiText::get('admin.account.name_saved'));

        return $this->redirectToRoute('admin_account');
    }

    #[Route('/admin/account/password', name: 'admin_account_password', methods: ['POST'])]
    public function password(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser || !$this->isCsrfTokenValid('admin_account_password', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.account.error_csrf'));

            return $this->redirectToRoute('admin_account');
        }
        $current = (string) $request->request->get('current_password', '');
        $new = (string) $request->request->get('new_password', '');
        if (!$this->passwords->isPasswordValid($user, $current)) {
            sleep(1);
            $this->addFlash('error', CanonicalUiText::get('admin.account.current_wrong'));
        } elseif ($new !== (string) $request->request->get('new_password_confirm', '')) {
            $this->addFlash('error', CanonicalUiText::get('admin.reset.mismatch'));
        } elseif (strlen($new) < 12 || strlen($new) > 4096) {
            $this->addFlash('error', CanonicalUiText::get('admin.reset.too_short'));
        } elseif ($new === $current) {
            $this->addFlash('error', CanonicalUiText::get('admin.account.same_password'));
        } else {
            $this->db->update('mc_admin_user', ['password_hash' => $this->passwords->hashPassword($user, $new)], ['id' => $user->id]);
            $this->addFlash('success', CanonicalUiText::get('admin.account.password_saved'));

            // The stored hash changed, so Symfony ends this session: the next request asks for the new password.
            return $this->redirectToRoute('admin_login');
        }

        return $this->redirectToRoute('admin_account');
    }
}
