<?php

declare(strict_types=1);

namespace Commerce\Core\Install\Http;

use Commerce\Core\Install\InstallCommand;
use Commerce\Core\Install\InstallationHealthVerifier;
use Commerce\Core\Install\InstallationState;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class WebInstallController extends AbstractController
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly InstallationHealthVerifier $healthVerifier,
        private readonly InstallCommand $installCommand,
        private readonly KernelInterface $kernel,
        private readonly string $projectDir,
    ) {
    }

    #[Route('/install', name: 'commerce_web_install', methods: ['GET'])]
    public function start(): Response
    {
        if ($this->state->isInstalled()) {
            return new RedirectResponse('/admin/login', Response::HTTP_SEE_OTHER);
        }

        return new RedirectResponse('/setup.php', Response::HTTP_SEE_OTHER);
    }

    #[Route('/install/finish', name: 'commerce_web_install_finish', methods: ['GET'])]
    public function finish(Request $request): Response
    {
        if ($this->state->isInstalled()) {
            return new RedirectResponse('/admin/login', Response::HTTP_SEE_OTHER);
        }

        $token = strtolower(trim((string) $request->query->get('token', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.nekorektnyi_abo_prostrochenyi_token_vstanovlennia'), Response::HTTP_BAD_REQUEST);
        }

        $requestFile = $this->projectDir . '/var/install/request-' . $token . '.json';
        if (!is_file($requestFile)) {
            return $this->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.zapyt_vstanovlennia_vidsutnii_abo_vzhe_vykorystanyi'), Response::HTTP_GONE);
        }

        $raw = (string) file_get_contents($requestFile);
        try {
            /** @var array<string,mixed> $payload */
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            @unlink($requestFile);
            return $this->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.zapyt_vstanovlennia_poshkodzhenyi_abo_nekorektnyi'), Response::HTTP_BAD_REQUEST);
        }

        $createdAt = strtotime((string) ($payload['created_at'] ?? '')) ?: 0;
        if ($createdAt < time() - 1800) {
            @unlink($requestFile);
            return $this->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.chas_dii_zapytu_vstanovlennia_mynuv_pochnit_znovu_z_'), Response::HTTP_GONE);
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $arguments = [
            'command' => 'commerce:install',
            '--store-name' => (string) ($payload['store_name'] ?? ''),
            '--admin-name' => (string) ($payload['admin_name'] ?? ''),
            '--admin-email' => (string) ($payload['admin_email'] ?? ''),
            '--admin-password' => (string) ($payload['admin_password'] ?? ''),
            '--public-url' => (string) ($payload['public_url'] ?? ''),
            '--site-mode' => (string) ($payload['site_mode'] ?? 'shop'),
            '--country' => (string) ($payload['country'] ?? 'UA'),
            '--currency' => (string) ($payload['currency'] ?? ''),
            '--locale' => (string) ($payload['locale'] ?? ''),
            '--timezone' => (string) ($payload['timezone'] ?? ''),
            '--no-interaction' => true,
        ];
        if ((bool) ($payload['install_demo'] ?? false)) {
            $arguments['--demo'] = true;
        }

        try {
            $consoleInput = new ArrayInput($arguments);
            $consoleInput->setInteractive(false);
            $this->installCommand->setApplication($application);
            $code = $this->installCommand->run($consoleInput, $output);
        } catch (\Throwable $e) {
            $this->logInstallException($e);
            $code = 1;
            $output->writeln(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.vstanovlennia_zavershylosia_pomylkoiu') . \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        } finally {
            $this->wipeRequestFile($requestFile);
        }

        if ($code !== 0 || !$this->state->isInstalled()) {
            return $this->error(\Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.vstanovlennia_ne_zaversheno') . $output->fetch(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $health = $this->healthVerifier->verify();
        $failedChecks = array_values(array_filter($health['checks'], static fn (array $check): bool => !$check['passed']));
        $diagnosticHtml = '<div class="ok"><strong>'
            . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.summary_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</strong><ul>';
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.php_ok') . ' · ' . PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.database_ok'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        foreach ($health['checks'] as $check) {
            $diagnosticHtml .= '<li>' . htmlspecialchars($check['label'] . ' — ' . ($check['passed'] ? 'OK' : $check['current']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.kernel_ok'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.cron_required'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.email_required'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        $diagnosticHtml .= '<li>' . htmlspecialchars(\Commerce\Core\I18n\CanonicalUiText::get('install.finish.backup_recommended'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        $diagnosticHtml .= '</ul></div>';
        if ($failedChecks !== []) {
            $diagnosticHtml .= \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.div_class_warning_strong_potribna_uvaha_pislia_vstan');
            foreach ($failedChecks as $check) {
                $diagnosticHtml .= '<li>' . htmlspecialchars($check['label'] . ': ' . $check['current'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $diagnosticHtml .= '</ul></div>';
        }

        $demoText = (bool) ($payload['install_demo'] ?? false)
            ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.p_bulo_zapytano_prezentatsiine_demo_yakshcho_okremyi')
            : '';

        return new Response($this->page(
            \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.vstanovlennia_zaversheno'),
            \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.p_modern_commerce_vstanovleno_pislia_vstanovlennia_n')
            . $diagnosticHtml
            . $demoText
            . \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.p_a_class_button_href_admin_login_vidkryty_panel_ker')
            . \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.p_class_muted_instaliator_zablokovano_zalyshte_ves_k'),
        ));
    }

    private function logInstallException(\Throwable $exception): void
    {
        error_log((string) json_encode([
            'event' => 'browser_install_exception',
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function wipeRequestFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if (is_int($size) && $size > 0 && $size <= 1024 * 1024) {
            @file_put_contents($path, str_repeat("\0", $size), LOCK_EX);
        }
        @unlink($path);
    }

    private function error(string $message, int $status): Response
    {
        return new Response($this->page(
            \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.problema_vstanovlennia'),
            '<pre>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . \Commerce\Core\I18n\CanonicalUiText::get('php.core.install.http.webinstallcontroller.pre_p_a_class_button_href_setup_php_povernutysia_do_'),
        ), $status);
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="uk-UA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><style>body{margin:0;background:#f5f7fb;color:#172033;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:760px;margin:60px auto;padding:0 20px}.card{background:#fff;border:1px solid #dfe5ef;border-radius:18px;padding:30px;box-shadow:0 18px 50px rgba(29,43,76,.08)}h1{margin-top:0}.button{display:inline-flex;background:#165dff;color:#fff;text-decoration:none;border-radius:10px;padding:12px 16px;font-weight:700}.muted{color:#667085}.ok{padding:12px;border-radius:10px;background:#f0fff4;border:1px solid #b7e4c7}.warning{padding:12px;border-radius:10px;background:#fff8e8;border:1px solid #f3d58a}pre{white-space:pre-wrap;background:#f7f8fa;border-radius:10px;padding:14px;overflow:auto}</style></head><body><main class="wrap"><section class="card"><h1>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>' . $body . '</section></main></body></html>';
    }
}
