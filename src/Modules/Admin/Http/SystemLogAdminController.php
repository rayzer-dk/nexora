<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin → System → Logs and errors: the tail of the application logs (var/log/*.log) with a level filter and search, a download
 * and a "clear" button. Only files that really sit in var/log can be opened; the file name is never used as a path.
 */
final class SystemLogAdminController extends AbstractController
{
    private const TAIL_BYTES = 1_048_576;
    private const LEVELS = ['all', 'error', 'warning'];
    private const LIMITS = [100, 300, 1000];

    public function __construct(
        private readonly AdminContextResolver $contexts,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/admin/system/logs', name: 'admin_system_logs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $files = $this->files();
        $name = (string) $request->query->get('file', '');
        if (!isset($files[$name])) {
            $name = isset($files['prod.log']) ? 'prod.log' : (string) array_key_first($files);
        }
        $level = in_array((string) $request->query->get('level', 'all'), self::LEVELS, true) ? (string) $request->query->get('level', 'all') : 'all';
        $limit = in_array((int) $request->query->get('limit', 300), self::LIMITS, true) ? (int) $request->query->get('limit', 300) : 300;
        $search = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);

        $entries = [];
        $counts = ['error' => 0, 'warning' => 0, 'other' => 0];
        if ($name !== '') {
            foreach ($this->tail($files[$name]['path']) as $line) {
                $severity = $this->severity($line);
                ++$counts[$severity];
                if ($level === 'error' && $severity !== 'error') {
                    continue;
                }
                if ($level === 'warning' && $severity === 'other') {
                    continue;
                }
                if ($search !== '' && mb_stripos($line, $search) === false) {
                    continue;
                }
                $entries[] = ['severity' => $severity, 'text' => mb_substr($line, 0, 2000)];
            }
        }
        $entries = array_slice(array_reverse($entries), 0, $limit);

        return $this->render('@storefront/admin/system/logs.html.twig', [
            'files' => $files, 'file' => $name, 'level' => $level, 'limit' => $limit, 'limits' => self::LIMITS, 'search' => $search,
            'entries' => $entries, 'counts' => $counts,
        ]);
    }

    #[Route('/admin/system/logs/download', name: 'admin_system_logs_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $this->contexts->resolve($request);
        $files = $this->files();
        $name = (string) $request->query->get('file', '');
        if (!isset($files[$name])) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($files[$name]['path']);
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $response;
    }

    #[Route('/admin/system/logs/clear', name: 'admin_system_logs_clear', methods: ['POST'])]
    public function clear(Request $request): RedirectResponse
    {
        $this->contexts->resolve($request);
        $files = $this->files();
        $name = (string) $request->request->get('file', '');
        if (!$this->isCsrfTokenValid('admin_system_logs_clear', (string) $request->request->get('_token')) || !isset($files[$name])) {
            $this->addFlash('error', CanonicalUiText::get('admin.logs.clear_failed'));

            return $this->redirectToRoute('admin_system_logs');
        }
        @file_put_contents($files[$name]['path'], '', LOCK_EX);
        $this->addFlash('success', CanonicalUiText::get('admin.logs.cleared'));

        return $this->redirectToRoute('admin_system_logs', ['file' => $name]);
    }

    /** @return array<string,array{path:string,size:int,modified:int}> */
    private function files(): array
    {
        $out = [];
        $dir = $this->projectDir . '/var/log';
        foreach (glob($dir . '/*.log') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $out[basename($path)] = ['path' => $path, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
            }
        }
        uasort($out, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /** @return list<string> the last lines of the file (at most the final megabyte) */
    private function tail(string $path): array
    {
        $size = (int) filesize($path);
        if ($size === 0) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $start = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $start);
        $data = (string) stream_get_contents($handle);
        fclose($handle);
        $lines = preg_split('/\R/u', $data) ?: [];
        if ($start > 0) {
            array_shift($lines); // the first line is cut in the middle
        }

        return array_values(array_filter(array_map('rtrim', $lines), static fn (string $line): bool => $line !== ''));
    }

    private function severity(string $line): string
    {
        if (preg_match('/\[(critical|alert|emergency|error)\]|\.(CRITICAL|ALERT|EMERGENCY|ERROR):|\b(Fatal error|Uncaught)\b/i', $line) === 1) {
            return 'error';
        }
        if (preg_match('/\[warning\]|\.WARNING:|\bWarning:/i', $line) === 1) {
            return 'warning';
        }

        return 'other';
    }
}
