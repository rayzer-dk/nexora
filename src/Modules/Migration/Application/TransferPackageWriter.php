<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use JsonException;
use RuntimeException;

final class TransferPackageWriter
{
    public function export(MigrationSourceInterface $source, string $directory, int $batchSize = 500): void
    {
        $dataDirectory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0700, true) && !is_dir($dataDirectory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8558fcd0bdc2'));
        }

        $files = [];
        foreach ($source->supportedEntities() as $type) {
            $relative = 'data/' . $type->value . '.ndjson';
            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e88d35737506'));
            }
            try {
                $cursor = null;
                do {
                    $batch = $source->read($type, $cursor, $batchSize);
                    foreach ($batch->records as $record) {
                        try {
                            $line = json_encode([
                                'source_key' => $record->sourceKey,
                                'data' => $record->data,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        } catch (JsonException $e) {
                            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.53fba8356769'), 0, $e);
                        }
                        fwrite($handle, $line . "\n");
                    }
                    $cursor = $batch->nextCursor;
                } while (!$batch->complete);
            } finally {
                fclose($handle);
            }
            @chmod($path, 0600);
            $files[$type->value] = $relative;
        }

        $manifest = [
            'format' => 'nexora-commerce-transfer',
            'version' => 1,
            'source_system' => $source->code(),
            'exported_at' => gmdate('c'),
            'files' => $files,
        ];
        try {
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5cc61ce1a14f'), 0, $e);
        }
        file_put_contents(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.json', $json . "\n", LOCK_EX);
    }
}
