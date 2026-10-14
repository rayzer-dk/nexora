# Отримувач вебхука замовлення

`receiver.php` — один файл: перевіряє підпис `X-Nexora-Signature`, відсікає повтори (replay-вікно 300 с і дублікати за `id` події) і передає `commerce.order.placed` у CRM (у прикладі — рядок у JSONL-файлі).

    NEXORA_WEBHOOK_SECRET=<secret з відповіді POST /api/v1/webhooks>  CRM_ORDER_LOG=/var/crm/orders.jsonl

Файл не є розширенням і не пакується: він живе на боці CRM. Покрокове підключення — у `docs/DEVELOPER_MODULES_UK.md`.
