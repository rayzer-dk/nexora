<?php

declare(strict_types=1);

return [
    'common.security.invalid_csrf' => 'Invalid security token. Refresh the page and try again.',
    'common.security.invalid_unsubscribe_signature' => 'Invalid unsubscribe link signature.',
    'common.error.operation_failed' => 'The operation failed due to an internal error. Try again or check the system log.',
    'install.error.seed_failed' => 'Failed to create the store\'s initial data. Technical details have been logged.',
    'install.error.demo_failed' => 'The main store was installed, but demo data was not created. Technical details have been logged.',
    'install.health.database_failed' => 'Database check failed',
    'install.health.details_hidden' => 'Technical details hidden',
    'install.health.migrations' => 'Database migrations',
    'install.finish.summary_title' => 'Installation summary',
    'install.finish.php_ok' => 'PHP — OK',
    'install.finish.database_ok' => 'Database — OK',
    'install.finish.kernel_ok' => 'Symfony Kernel — OK',
    'install.finish.cron_required' => 'Cron — requires one-time setup on the server',
    'install.finish.email_required' => 'Email — not configured, using the safe null transport',
    'install.finish.backup_recommended' => 'Backup — it\'s recommended to create your first backup after logging into the admin panel',
    'extension.localization.invalid' => 'Invalid extension localization block.',
    'extension.localization.default_uk_required' => 'The extension\'s default_locale must be uk-UA.',
    'extension.localization.locales_invalid' => 'The extension\'s locales list is invalid and must include uk-UA.',
    'extension.localization.path_invalid' => 'The extension\'s translations_path is invalid.',
    'extension.localization.manifest_required' => 'Translation files require a localization description in manifest.json.',
    'extension.localization.file_invalid' => 'The extension localization file must be safe JSON at the declared translations_path.',
    'extension.localization.file_missing' => 'Missing localization file for locale: ',
    'extension.localization.json_invalid' => 'The extension localization JSON catalog is invalid.',
    'extension.localization.namespace_invalid' => 'The extension localization key must belong to the namespace ',
    'extension.localization.duplicate_key' => 'Duplicate extension localization key: ',
    'extension.localization.namespace_collision' => 'The localization namespace conflicts with an already installed extension: ',
];
