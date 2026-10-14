# example.remote_crm

Паспорт віддаленої інтеграції (`execution: remote_app`): назва, права, адреса зовнішнього сервісу в `remote.base_url`, переклади.
Приклад отримувача вебхука лежить поруч: `docs/examples/extensions/remote-crm-receiver/`.

    php bin/console commerce:extension:pack docs/examples/extensions/remote-crm --output=var/remote-crm.zip

Що важливо знати: платформа перевіряє лише, що `remote.base_url` — публічний HTTPS. Блок `remote` (`health_path`, `webhook_path`) сьогодні нічого не викликає:
самі замовлення доставляє вебхук Public API (`POST /api/v1/webhooks`), див. розділ про віддалену інтеграцію в `docs/DEVELOPER_MODULES_UK.md`.
