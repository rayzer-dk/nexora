Nexora Commerce — бонусні розширення та теми

Ці пакети НЕ встановлюються автоматично і не навантажують магазин.

extensions/packages:
Product_Trust_Badge_v1.0.1.zip — declarative приклад.
Remote_CRM_Connector_Starter_v1.0.0.zip — remote_app шаблон інтеграції. Перед production використанням розробник замінює example.com на свій HTTPS API.
Store_Health_Endpoint_v1.0.0.zip — підписаний trusted_release приклад.
Для Nexora 4.0 цей пакет треба перепідписати, інакше він не встановиться: php bin/console commerce:extension:pack bonus/extensions/src/nexora.store_health -o bonus/extensions/packages/Store_Health_Endpoint_v1.0.0.zip, потім php bin/console commerce:extension:sign bonus/extensions/packages/Store_Health_Endpoint_v1.0.0.zip <шлях до секретного ключа nexora.bonus.2026>.

themes/packages:
Nexora_Market_Theme_v1.0.0.zip — щільний каталог.
Nexora_Atelier_Theme_v1.0.0.zip — преміальний просторий стиль.
Nexora_Soft_Theme_v1.0.0.zip — м'який сучасний стиль.

Вбудовані пресети Modern, Marketplace, Premium, Minimal і Soft доступні без встановлення ZIP.
