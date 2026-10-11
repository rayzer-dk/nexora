-- 3) Слайдшоу главной (banner_id=7): новые 4 слайда на каждый язык, с ссылками.
SET NAMES utf8mb4;
START TRANSACTION;
DELETE FROM `oc_banner_image` WHERE `banner_id`=7;
INSERT INTO `oc_banner_image` (`banner_id`,`language_id`,`title`,`link`,`image`,`sort_order`) VALUES
(7,3,'CodeCart PRO 3.0.6.0 — бесплатная система OpenCart','/skachat/','catalog/codecartpro/v2/banners/ru/slide-1.webp',1),
(7,3,'70+ модулей для OpenCart и ocStore','/codecartpro-ru/','catalog/codecartpro/v2/banners/ru/slide-2.webp',2),
(7,3,'AI-модули CodeCart PRO','/ai-moduli-ru/','catalog/codecartpro/v2/banners/ru/slide-3.webp',3),
(7,3,'Демо-админка модулей CodeCart PRO','https://demo.codecartpro.com/admin/','catalog/codecartpro/v2/banners/ru/slide-4.webp',4),
(7,1,'CodeCart PRO 3.0.6.0 — безкоштовна система OpenCart','/download/','catalog/codecartpro/v2/banners/uk/slide-1.webp',1),
(7,1,'70+ модулів для OpenCart та ocStore','/codecartpro/','catalog/codecartpro/v2/banners/uk/slide-2.webp',2),
(7,1,'AI-модулі CodeCart PRO','/ai-moduli/','catalog/codecartpro/v2/banners/uk/slide-3.webp',3),
(7,1,'Демо-адмінка модулів CodeCart PRO','https://demo.codecartpro.com/admin/','catalog/codecartpro/v2/banners/uk/slide-4.webp',4),
(7,2,'CodeCart PRO 3.0.6.0 — free OpenCart system','/download-en/','catalog/codecartpro/v2/banners/en/slide-1.webp',1),
(7,2,'70+ modules for OpenCart and ocStore','/codecartpro-en/','catalog/codecartpro/v2/banners/en/slide-2.webp',2),
(7,2,'CodeCart PRO AI modules','/ai-modules/','catalog/codecartpro/v2/banners/en/slide-3.webp',3),
(7,2,'CodeCart PRO modules demo admin','https://demo.codecartpro.com/admin/','catalog/codecartpro/v2/banners/en/slide-4.webp',4);
-- размер слайдшоу под новые баннеры 3:1
UPDATE `oc_module` SET `setting`=REPLACE(REPLACE(`setting`,'"width":"1140"','"width":"1500"'),'"height":"380"','"height":"500"') WHERE `module_id`=27;
COMMIT;
