<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Provider;

use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Turns a failed provider response into an exception that says what the provider answered (status and its own message). */
final class AiHttpError
{
    public static function from(ResponseInterface $response): RuntimeException
    {
        $status = $response->getStatusCode();
        $message = '';
        try {
            $data = $response->toArray(false);
            $error = $data['error'] ?? null;
            $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) ($error ?? '');
        } catch (\Throwable) {
            $message = '';
        }
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        return new RuntimeException('HTTP ' . $status . ($message !== '' ? ': ' . mb_substr($message, 0, 240) : ''));
    }
}
