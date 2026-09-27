-- Nexora Commerce schema migration 011
-- Stable system content keys for public information pages.

ALTER TABLE mc_content_entry
    ADD COLUMN system_key VARCHAR(64) NULL AFTER content_type,
    ADD UNIQUE KEY uq_content_store_system_key (store_id, system_key);

UPDATE mc_content_entry ce
JOIN mc_content_translation ct ON ct.content_id = ce.id AND ct.locale = 'uk-UA'
SET ce.system_key = CASE ct.title
    WHEN 'Про компанію' THEN 'about'
    WHEN 'Контакти' THEN 'contacts'
    WHEN 'Доставка' THEN 'delivery'
    WHEN 'Оплата' THEN 'payment'
    WHEN 'Повернення та обмін' THEN 'returns'
    WHEN 'Гарантія' THEN 'warranty'
    WHEN 'Часті запитання' THEN 'faq'
    WHEN 'Політика конфіденційності' THEN 'privacy'
    WHEN 'Політика Cookie' THEN 'cookies'
    WHEN 'Умови та положення' THEN 'terms'
    ELSE NULL
END
WHERE ce.content_type = 'page'
  AND ce.system_key IS NULL
  AND ct.title IN ('Про компанію','Контакти','Доставка','Оплата','Повернення та обмін','Гарантія','Часті запитання','Політика конфіденційності','Політика Cookie','Умови та положення');
