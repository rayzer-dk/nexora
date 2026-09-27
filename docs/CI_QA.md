# Continuous integration QA

The repository includes a CI matrix for PHP 8.4 and 8.5 against MySQL 8.4 LTS and MariaDB 11.4 LTS. Every matrix run installs dependencies, applies the complete migration chain to an empty database, runs the same platform preflight used by installation/update, runs PHPUnit, Composer security audit, TypeScript typecheck, Vite production build and npm production dependency audit.

Release promotion must additionally test an upgrade from the previous released database snapshot, backup/restore, payment/shipping sandbox integrations and browser smoke tests. A release is not marked stable merely because static lint passes.
