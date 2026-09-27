# Вимоги до сервера

Обов'язково: PHP 8.4–8.5; MySQL 8.4+ або MariaDB 11.4+; InnoDB; utf8mb4; HTTPS; ctype, curl, dom, fileinfo, gd, iconv, intl, json, mbstring, openssl, pcre, PDO, pdo_mysql, session, simplexml, sodium, tokenizer, zip.

Рекомендовано: OPcache, HTTP/2 або HTTP/3, 256M+ PHP memory_limit, достатні upload/post limits для зображень.

Необов'язково: Redis, gRPC/protobuf для Google high-throughput, високопродуктивний image adapter.

Document Root домену: `<application>/public/`.
