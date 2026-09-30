# Server baseline

Supported PHP range: >=8.4 <9.0 (Symfony 8.1 requires PHP 8.4 or higher). Verified on 8.4 and 8.5.

Required PHP extensions: ctype, curl, DOM, fileinfo, GD, iconv, intl, json, mbstring, openssl, pcre, PDO, pdo_mysql, session, SimpleXML, sodium, tokenizer and ZIP. OPcache is strongly recommended. Redis and Imagick/libvips are optional accelerators.

Database baseline: MySQL 8.4 LTS or MariaDB 11.4 LTS, InnoDB, utf8mb4 and strict SQL mode. These are the certified production targets for this release. The preflight checker detects the actual server family/version instead of trusting an environment label.

Web edge: HTTPS/TLS 1.3 where available, HTTP/2 baseline, HTTP/3/QUIC when supported by the reverse proxy/CDN. Compression is negotiated by the server/CDN (Brotli/Zstandard/Gzip as supported); PHP application code does not double-compress responses.

Workers, Redis, external search, object storage and CDN are scale-out options. A small store must remain operable with PHP-FPM + supported MySQL/MariaDB + local storage.
