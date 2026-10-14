# example.order_hook

Підписаний trusted-модуль з PHP-кодом, що показує три речі одразу:

- підписку на подію `commerce.order.placed` (кожне нове замовлення пишеться в журнал модуля);
- cron-задачу `digest` (раз на годину рахує нові замовлення; видно в Система → Cron як «Order hook digest»);
- сторінку адмінки `/admin/extensions/example_order_hook/log` з правом `extension.example_order_hook.view`.

Файли: `manifest.json`, `src/Entrypoint.php` (реєстрація обробників через `TrustedExtensionContext`), `src/OrderLog.php` (журнал у JSON-рядках), переклади назви.

Збирання й підпис (ключ — поза репозиторієм модуля):

    php bin/console commerce:extension:keygen example.docs.2026 --out=/шлях/поза/репозиторієм/example.docs.2026.key
    php bin/console commerce:extension:pack docs/examples/extensions/trusted-order-hook --output=var/order-hook.zip
    php bin/console commerce:extension:sign var/order-hook.zip /шлях/поза/репозиторієм/example.docs.2026.key
    php bin/console commerce:extension:validate var/order-hook.zip

`keygen` друкує рядок для `var/config/trusted-publishers.json` магазину: без нього `validate` і встановлення відхилять підпис. `key_id` у `manifest.json` (`example.docs.2026`) має збігатися з тим, що ви передали `keygen`; для власного модуля візьміть свій.

Чесно про обмеження: trusted-модуль не отримує ні бази даних, ні сховища, ні своїх налаштувань, тому журнал лежить у файлі `var/extension-data/example.order_hook/`. Обробник події викликається воркером черги подій уже після збереження замовлення: змінити або скасувати замовлення він не може.
Докладно: `docs/DEVELOPER_MODULES_UK.md`.
