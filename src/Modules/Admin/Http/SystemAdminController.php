<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Extension\ExtensionPackageManager;
use Commerce\Core\Extension\ExtensionSettingsManager;
use Commerce\Core\Health\SystemPreflightInspector;
use Commerce\Core\Install\InstallationHealthVerifier;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Recovery\RecoverySnapshotService;
use Commerce\Core\Recovery\RecoveryArchiveRestorer;
use Commerce\Core\Recovery\NativeDatabaseConnectionFactory;
use Commerce\Core\Runtime\MaintenanceMode;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SystemAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly ExtensionPackageManager $extensions,
        private readonly ExtensionSettingsManager $extensionSettings,
        private readonly InstallationHealthVerifier $health,
        private readonly SystemPreflightInspector $preflight,
        private readonly RecoverySnapshotService $recovery,
        private readonly MaintenanceMode $maintenance,
    ) {
    }

    #[Route('/admin/system/site', name: 'admin_system_site', methods: ['GET', 'POST'])]
    public function site(Request $request, Connection $connection): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('site_capabilities', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.sesiia_formy_zavershylas_onovit_storinku_ta_povtorit'));
                return $this->redirectToRoute('admin_system_site');
            }

            $mode = trim((string) $request->request->get('mode', SiteCapabilitySettings::MODE_SHOP));
            $features = [];
            foreach (array_keys(SiteCapabilitySettings::profile(SiteCapabilitySettings::MODE_SHOP)['features']) as $feature) {
                $features[$feature] = $request->request->has('feature_' . $feature);
            }
            try {
                $this->capabilities->save($context->storeId, ['mode' => $mode, 'features' => $features], $this->actor());
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.profil_saitu_zastosovano_poperednia_konfihuratsiia_z'));
            } catch (Throwable $e) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.zminy_ne_zastosovano') . $this->safeMessage($e));
            }
            return $this->redirectToRoute('admin_system_site');
        }

        $store = $connection->fetchAssociative('SELECT name FROM mc_store WHERE id=?', [$context->storeId]) ?: [];
        return $this->render('@storefront/admin/system/site.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'store' => $store,
            'settings' => $this->capabilities->get($context->storeId),
            'modes' => SiteCapabilitySettings::modes(),
            'feature_labels' => $this->featureLabels(),
            'revisions' => $this->capabilities->history($context->storeId, 12),
        ]);
    }

    #[Route('/admin/system/site/rollback/{revisionId}', name: 'admin_system_site_rollback', methods: ['POST'], requirements: ['revisionId' => '\\d+'])]
    public function rollbackSite(Request $request, int $revisionId): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('site_capabilities_rollback', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_system_site');
        }
        try {
            $this->capabilities->rollback($context->storeId, $revisionId, $this->actor());
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.poperedniu_konfihuratsiiu_saitu_vidnovleno_iak_novu_'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_site');
    }

    #[Route('/admin/system/extensions', name: 'admin_system_extensions', methods: ['GET', 'POST'])]
    public function extensions(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('extension_install', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_paket_ne_vstanovleno'));
                return $this->redirectToRoute('admin_system_extensions');
            }
            $archive = $request->files->get('extension_package');
            if (!$archive instanceof UploadedFile || !$archive->isValid()) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.oberit_korektnyi_zip_paket_rozshyrennia'));
                return $this->redirectToRoute('admin_system_extensions');
            }
            try {
                $result = $this->extensions->install($archive->getPathname());
                if ($result['quarantined']) {
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.paket_izolovano_v_karantyni_vykonuvanyi_abo_potentsi'));
                } else {
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.paket_perevireno_ta_pidhotovleno_vin_shche_ne_aktyvn'));
                }
                foreach ($result['warnings'] as $warning) {
                    $this->addFlash('warning', $warning);
                }
            } catch (Throwable $e) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.paket_vidkhyleno_do_vstanovlennia') . $this->safeMessage($e));
            }
            return $this->redirectToRoute('admin_system_extensions');
        }

        try {
            $extensions = $this->extensions->list();
        } catch (Throwable $e) {
            $extensions = [];
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.reiestr_rozshyren_nedostupnyi') . $this->safeMessage($e));
        }

        return $this->render('@storefront/admin/system/extensions.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'extensions' => $extensions,
            'extension_lifecycle' => $this->extensions->lifecycle(100),
        ]);
    }

    #[Route('/admin/system/extensions/{id}/settings', name: 'admin_system_extension_settings', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function extensionSettings(Request $request, int $id): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('extension_settings_' . $id, (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_nalashtuvannia_ne_zmineno'));
                return $this->redirectToRoute('admin_system_extension_settings', ['id' => $id]);
            }
            try {
                $input = $request->request->all('settings');
                $this->extensionSettings->save($id, is_array($input) ? $input : [], $this->actor());
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nalashtuvannia_perevireno_i_zberezheno_iak_novu_revi'));
            } catch (Throwable $e) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nalashtuvannia_ne_zastosovano') . $this->safeMessage($e));
            }
            return $this->redirectToRoute('admin_system_extension_settings', ['id' => $id]);
        }

        try {
            $editor = $this->extensionSettings->editor($id);
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nalashtuvannia_rozshyrennia_nedostupni') . $this->safeMessage($e));
            return $this->redirectToRoute('admin_system_extensions');
        }

        return $this->render('@storefront/admin/system/extension_settings.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            ...$editor,
        ]);
    }

    #[Route('/admin/system/extensions/{id}/settings/rollback/{revisionId}', name: 'admin_system_extension_settings_rollback', methods: ['POST'], requirements: ['id' => '\d+', 'revisionId' => '\d+'])]
    public function rollbackExtensionSettings(Request $request, int $id, int $revisionId): Response
    {
        if (!$this->isCsrfTokenValid('extension_settings_rollback_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_vidkat_ne_vykonano'));
            return $this->redirectToRoute('admin_system_extension_settings', ['id' => $id]);
        }
        try {
            $this->extensionSettings->rollback($id, $revisionId, $this->actor());
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.poperedniu_reviziiu_nalashtuvan_vidnovleno_pered_vid'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vidkat_nalashtuvan_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_extension_settings', ['id' => $id]);
    }

    #[Route('/admin/system/extensions/{id}/activate', name: 'admin_system_extension_activate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function activateExtension(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('extension_state_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_rozshyrennia_ne_aktyvovano'));
            return $this->redirectToRoute('admin_system_extensions');
        }
        try {
            $this->extensions->activate($id);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.rozshyrennia_aktyvovano_atomarno_yakshcho_pidhotovka'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.aktyvatsiiu_skasovano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_extensions');
    }

    #[Route('/admin/system/extensions/{id}/disable', name: 'admin_system_extension_disable', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function disableExtension(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('extension_state_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_stan_ne_zmineno'));
            return $this->redirectToRoute('admin_system_extensions');
        }
        try {
            $this->extensions->disable($id);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.rozshyrennia_vymkneno_yadro_ta_dani_mahazynu_ne_zmin'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.ne_vdalosia_vymknuty_rozshyrennia') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_extensions');
    }

    #[Route('/admin/system/extensions/{id}/rollback', name: 'admin_system_extension_rollback', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function rollbackExtension(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('extension_state_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_vidkat_ne_vykonano'));
            return $this->redirectToRoute('admin_system_extensions');
        }
        try {
            $restored = $this->extensions->rollbackPrevious($id);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vidnovleno_poperedniu_perevirenu_versiiu') . $restored['code'] . ' ' . $restored['version'] . \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.peremykannia_vykonano_lyshe_pislia_uspishnoi_pidhoto'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_extensions');
    }

    #[Route('/admin/system/extensions/isolate-all', name: 'admin_system_extensions_isolate_all', methods: ['POST'])]
    public function isolateAllExtensions(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('extension_isolate_all', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_rozshyrennia_ne_zminiuvalysi'));
            return $this->redirectToRoute('admin_system_stability');
        }
        try {
            $count = $this->extensions->disableAllOptional();
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.bezpechnyi_rezhym_zastosovano_vymkneno_dodatkovykh_a') . $count . \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vbudovani_funktsii_iadra_ne_zminiuvalysia'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.bezpechnyi_rezhym_ne_zastosovano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/system/stability', name: 'admin_system_stability', methods: ['GET'])]
    public function stability(Connection $connection): Response
    {
        $incidents = [];
        try {
            $incidents = $connection->fetchAllAssociative('SELECT request_id,area,route_name,severity,fallback_mode,error_class,error_summary,created_at FROM mc_runtime_incident ORDER BY id DESC LIMIT 50');
        } catch (Throwable) {
            // The stability page must remain available while a migration is incomplete.
        }

        try {
            $snapshots = $this->recovery->list(20);
        } catch (Throwable) {
            $snapshots = [];
        }

        return $this->render('@storefront/admin/system/stability.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'health' => $this->safeHealth(),
            'runtime_checks' => $this->serializeRequirements($this->preflight->runtime()),
            'database_checks' => $this->serializeRequirements($this->preflight->database($connection)),
            'incidents' => $incidents,
            'recovery_snapshots' => $snapshots,
            'maintenance_state' => $this->maintenance->state(),
        ]);
    }

    #[Route('/admin/system/recovery/create', name: 'admin_system_recovery_create', methods: ['POST'])]
    public function createRecoverySnapshot(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('recovery_snapshot_create', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_backup_ne_stvoreno'));
            return $this->redirectToRoute('admin_system_stability');
        }
        $profile = (string) $request->request->get('profile', 'full');
        if (!in_array($profile, ['database', 'data', 'full'], true)) {
            $profile = 'full';
        }
        try {
            $snapshot = $this->recovery->create('manual-admin-' . $profile, $this->actor(), $profile === 'full', $profile);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_stvoreno_ta_perevireno') . $snapshot['snapshot_key'] . '.');
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_ne_stvoreno') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/system/recovery/upload-restore', name: 'admin_system_recovery_upload_restore', methods: ['POST'])]
    public function uploadAndRestoreRecovery(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('recovery_upload_restore', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('backup_archive');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.oberit_korektnyi_zip_backup_z_vashoho_pk'));
            return $this->redirectToRoute('admin_system_stability');
        }
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.pidtrymuietsia_lyshe_zip_backup_stvorenyi_tsiieiu_sy'));
            return $this->redirectToRoute('admin_system_stability');
        }
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $uploadDir = $projectDir . '/var/recovery/uploads';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.ne_vdalosia_pidhotuvaty_zakhyshchenu_papku_dlia_back'));
            return $this->redirectToRoute('admin_system_stability');
        }
        $path = $uploadDir . '/upload-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            $file->move($uploadDir, basename($path)); @chmod($path, 0600);
            $restorer = new RecoveryArchiveRestorer($projectDir);
            $inspection = $restorer->inspect($path);
            $profile = (string) ($inspection['manifest']['profile'] ?? 'recovery');
            if ($profile === 'full') {
                $snapshotKey = (string) ($inspection['manifest']['snapshot_key'] ?? '');
                if (preg_match('/^[A-Za-z0-9._-]{8,96}$/D', $snapshotKey) !== 1) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nekorektnyi_kliuch_povnoho_backup'));
                $targetDir = $projectDir . '/var/recovery/snapshots'; if (!is_dir($targetDir)) @mkdir($targetDir,0750,true);
                $target = $targetDir . '/' . $snapshotKey . '.zip'; if (is_file($target)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_z_takym_kliuchem_uzhe_ie_na_serveri'));
                if (!@rename($path,$target)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.ne_vdalosia_zberehty_povnyi_backup_u_recovery_storag'));
                @chmod($target,0600); $path='';
                $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.povnyi_backup_z_pk_perevireno_ta_zberezheno_na_serve').$snapshotKey.' --yes');
                return $this->redirectToRoute('admin_system_stability');
            }
            if (!in_array($profile, ['database', 'data'], true)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nepidtrymuvanyi_profil_backup'));
            $this->recovery->create('pre-upload-restore', $this->actor(), true, 'full');
            $pdo = NativeDatabaseConnectionFactory::fromProject($projectDir);
            $restorer->restore($path, $pdo, $profile === 'data', true);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_z_pk_perevireno_ta_vidnovleno_pered_operatsii'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_z_pk_ne_vidnovleno') . $this->safeMessage($e));
        } finally {
            if (is_file($path)) @unlink($path);
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/system/recovery/{id}/download', name: 'admin_system_recovery_download', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function downloadRecoverySnapshot(int $id): Response
    {
        $row = $this->recovery->get($id);
        $path = $row['archive_path_absolute'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $row['snapshot_key'] . '.zip');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/admin/system/recovery/{id}/delete', name: 'admin_system_recovery_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function deleteRecoverySnapshot(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('recovery_snapshot_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->recovery->delete($id);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_vydaleno_iz_servera'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.backup_ne_vydaleno') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/system/recovery/{id}/restore-data', name: 'admin_system_recovery_restore_data', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function restoreRecoveryData(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('recovery_restore_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $row = $this->recovery->get($id);
            $path = $row['archive_path_absolute'] ?? null;
            if (!is_string($path) || !is_file($path)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.arkhiv_backup_vidsutnii'));
            }
            $projectDir = (string) $this->getParameter('kernel.project_dir');
            $restorer = new RecoveryArchiveRestorer($projectDir);
            $inspection = $restorer->inspect($path);
            $profile = (string) ($inspection['manifest']['profile'] ?? 'recovery');
            if (!in_array($profile, ['database', 'data'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.povnyi_systemnyi_backup_vidnovliuietsia_cherez_avari'));
            }
            $this->recovery->create('pre-admin-restore', $this->actor(), true, 'full');
            $pdo = NativeDatabaseConnectionFactory::fromProject($projectDir);
            $restorer->restore($path, $pdo, $profile === 'data', true);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.dani_vidnovleno_perevirte_kataloh_zamovlennia_ta_med'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vidnovlennia_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/system/recovery/{id}/verify', name: 'admin_system_recovery_verify', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function verifyRecoverySnapshot(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('recovery_snapshot_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_perevirku_ne_vykonano'));
            return $this->redirectToRoute('admin_system_stability');
        }
        try {
            $result = $this->recovery->verify($id);
            if ($result['ok']) {
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.arkhiv_vidnovlennia_tsilisnyi_kontrolna_suma_pidtver'));
            } else {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.arkhiv_vidnovlennia_ne_proishov_perevirku') . $result['reason'] . '.');
            }
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.perevirku_ne_zaversheno') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_stability');
    }

    #[Route('/admin/api/system/health', name: 'admin_api_system_health', methods: ['GET'])]
    public function healthApi(Connection $connection): JsonResponse
    {
        $health = $this->safeHealth();
        $runtime = $this->serializeRequirements($this->preflight->runtime());
        $database = $this->serializeRequirements($this->preflight->database($connection));
        $all = array_merge($runtime, $database);
        $requiredFailed = count(array_filter($all, static fn (array $row): bool => $row['level'] === 'required' && !$row['passed']));

        return $this->json([
            'healthy' => (bool) ($health['healthy'] ?? false) && $requiredFailed === 0,
            'required_failed' => $requiredFailed,
            'installation' => $health,
            'runtime' => $runtime,
            'database' => $database,
            'checked_at' => gmdate('c'),
        ]);
    }

    private function actor(): string
    {
        $user = $this->getUser();
        return $user instanceof AdminUser ? 'admin:' . $user->id : 'admin';
    }

    /** @return array<string,string> */
    private function featureLabels(): array
    {
        return [
            'catalog' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.kataloh_i_tovary'),
            'search' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.poshuk'),
            'cart' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.koshyk'),
            'checkout' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.oformlennia_zamovlennia'),
            'content' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.informatsiini_storinky'),
            'blog' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.statti_ta_novyny'),
            'forum' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.forum_i_spilnota'),
            'reviews' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.vidhuky'),
            'customer_accounts' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.kabinety_pokuptsiv'),
        ];
    }

    /** @return array<string,mixed> */
    private function safeHealth(): array
    {
        try {
            return $this->health->verify();
        } catch (Throwable $e) {
            return ['healthy' => false, 'checks' => [['code' => 'health', 'label' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.perevirka_systemy'), 'passed' => false, 'current' => $this->safeMessage($e), 'expected' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.bez_pomylok')]]];
        }
    }

    /** @param iterable<object> $items @return list<array<string,mixed>> */
    private function serializeRequirements(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'code' => $item->code,
                'label' => $item->label,
                'passed' => $item->passed,
                'level' => $item->level->value,
                'current' => $item->current,
                'required' => $item->required,
                'action' => $item->action,
            ];
        }
        return $rows;
    }

    private function safeMessage(Throwable $e): string
    {
        $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
        return mb_substr($message, 0, 400, 'UTF-8');
    }
}
