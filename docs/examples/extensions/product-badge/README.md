# example.product_badge

Декларативний блок на сторінці товару без PHP: компонент Конструктора `extension.example_product_badge.badge` (поверхня `product`, регіони `below_primary` і `hero_summary`) та внесок у слот `product.after_price` з текстом із перекладів.

    php bin/console commerce:extension:pack docs/examples/extensions/product-badge --output=var/product-badge.zip

Після активації:

- внесок у слот одразу показується після блоку «Ціна» на сторінці товару (якщо блок «Ціна» є в макеті товару): заголовок і текст беруться з `translations/<мова>/messages.json`;
- у Оформлення → Конструктор → Товар у списку «Додати блок» з'являється «Позначка товару»; заголовок і текст блоку вводяться в звичайних полях Конструктора «Заголовок» і «Текст».

Чесно про обмеження: і слот, і блок виводять лише заголовок і текст (шаблон `themes/default/templates/product/blocks/extension_component.html.twig`), без власної розмітки, кнопок, стилів і даних товару. Файл `blocks/badge.schema.json` валідатор перевіряє, але Конструктор форму з нього не будує.
Докладно: `docs/DEVELOPER_MODULES_UK.md`.
