# Nexora source quality: Codex and Claude Code

- Use ASCII in PHP identifiers and executable syntax. Preserve real Ukrainian/Russian and other Unicode in interface text, translations, comments and content.
- Never insert invisible or bidi Unicode controls, Markdown fences in source, omitted-code placeholders, or HTML-escaped operators into PHP.
- Confirm real method signatures and existing project services before using any API; check PHPStan and tests.
- Do not create redundant guards, broad catch-and-ignore exceptions, duplicate implementations, pointless tests or excessive trivial comments.
- Check Deptrac architecture rules and protect independent modules from hidden mandatory dependencies.
- On main, Rector reviews changes read-only. A single explicitly selected first-party PHP file may be auto-corrected on main only by manual workflow dispatch with apply=true after PHPStan, PHPUnit, PHPCS, syntax and source guard checks; no changes are deployed automatically.


## Quality Fixer (Codex / Claude Code)

When CI fails or a defect is reported, act as the shared Quality Fixer role:
1. Reproduce the failure using the smallest relevant check; inspect CI logs and the affected dependency chain before editing.
2. Identify the root cause; avoid speculative mass changes, suppressions, duplicated implementations and patches on patches.
3. Make the smallest coherent fix, preserving architecture boundaries, RBAC, security, translations and light/dark layout behavior.
4. Run targeted tests and then applicable PHP syntax, PHPStan, PHPUnit, PHPCS, source integrity, duplication and Deptrac checks.
5. If a check cannot run locally, explain exactly which check and why. Do not claim a green build without results.
6. Stop on success or on a concrete blocker; do not loop indefinitely or change unrelated files merely to produce activity.
7. Give a concise report of root cause, files changed, checks and remaining risks.
Never bypass CI gates, merge automatically or expose credentials.

## Source integrity and Quality Fixer
Quality Fixer handles evidenced CI failures and regressions, invoking existing Source Guard, PHPStan, PHPCS, Deptrac, PHPUnit and scoped Rector checks. Check BOM, invisible and bidi controls, suspicious mixed-script identifiers, HTML-escaped executable operators, accidental Markdown fences and omitted-code placeholders. Avoid invented API calls, redundant guards, swallowed exceptions, duplicate code and pointless tests. Source files are UTF-8 without BOM; Excel-oriented CSV may optionally add BOM only at export. Never blanket-rewrite typographic quotes, dashes or Unicode within strings, translations, comments, Twig or documentation; token/context-aware analysis is mandatory. Automatically strip only confirmed leading BOM through the existing source guard; all other suspicious issues require source-aware review. Work directly in main after checking the latest state, validate adjacent dependencies, and never overwrite unrelated work. Stop when fixed or when a specific blocker is known.

<!-- autopilot:start -->
# Nexora Commerce

Универсальная e-commerce платформа (магазин, каталог, лендинг, форум, блог, B2B) на Symfony 8 + PHP 8.4 + MySQL 8.4, рынок: Украина → Европа → мир.

## Команды

| Команда | Что делает |
|---------|------------|
| `composer install` · `npm ci` | Установить зависимости |
| `composer quality:php` | UTF-8 без BOM, PHPStan, PHPCS, Deptrac, Rector dry-run |
| `composer analyse` · `composer cs` · `composer deptrac` · `composer rector:check` | Отдельные проверки PHP; без автоматических правок |
| `composer check:encoding` · `composer test:source-encoding` | Проверка кодировки и тесты защиты от BOM |
| `tools/quality.ps1 -Task analyse` | Те же команды на Windows, включая Laragon без PHP/Composer в PATH |
| `php bin/console commerce:install --demo …` | Установить магазин с демо-данными (см. `docs/INSTALLATION.md`) |
| `php -S 127.0.0.1:8000 -t public public/index.php` | Запустить локально |
| `npm run build:production` | Собрать фронтенд в `public/build` (не в git) |
| `php vendor/bin/phpunit` | Юнит-тесты (3 падения на Windows — окружение: WebPush OpenSSL, путь crontab) |
| `npm run typecheck` · `npx eslint assets` | Типы и линтер фронтенда |
| `npx playwright test` (`E2E_BASE_URL`, `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`) | E2E |
| `php bin/full-release-static-check.php`, `php bin/i18n-template-check.php` | Статические проверки релиза |

## Как здесь работает Autopilot

Правила инструментов разработки: `docs/DEVELOPMENT_QUALITY.md`. Исходники — UTF-8
без BOM. Codex и Claude Code запускают одни и те же Composer-команды. Rector
использовать только с `--dry-run`; не запускать массовое форматирование или
автоисправление вне согласованной задачи. Не скрывать найденные ошибки общими
ignore/baseline и не изменять чужие незавершённые правки ради зелёной проверки.

Сборка ведётся навыком `/autopilot`. Требования, спецификация и таски — в `.autopilot/`.
Прогресс — `.autopilot/dashboard.html`. Требование из `manifest.md` может снять
только пользователь. Прерванную сборку продолжает «продолжи автопилот».

### Правка без автопилота

Мелкий баг или доводка после сборки — обычная сессия, без прогона.

Сначала прочитай:
1. `.autopilot/PROGRESS.md` — что не доделано, известные баги, о чём договорились с пользователем.
2. Что правили после последней сборки — первая строка таблицы в «История git».
3. Только если правка их касается: почему сделано так — `docs/adr/`; что значат слова — `CONTEXT.md`;
   что именно просили — `manifest.md` в папке сборки (указана в начале `PROGRESS.md`).

После правки:
- коммит — по правилам из «История git»;
- строка в «Правки без автопилота» в `PROGRESS.md`: дата — что — почему;
- починенный пункт из «Не доделано» или «Известные баги» — удали;
- договорились с пользователем о том, что следующая сборка не должна пересматривать, —
  строка в «Договорились», его словами.

### История git

Целиком не читай — бери срез под вопрос:

| Вопрос | Команда |
|---|---|
| что правили после последней сборки | `git log --oneline "$(git log -1 --format=%h --grep='^Autopilot: сборка сдана')"..HEAD -- . ':(exclude).autopilot'` |
| всё, что делали ради требования | `git log --oneline --grep="R02"` |
| история файла | `git log --oneline -10 -- <файл>` |
| когда появился или исчез код | `git log --oneline -S "<фрагмент>"` |
| почему строка такая | `git blame -L <с>,<по> <файл>`, затем `git show <sha>` |
| что внутри коммита | `git show --stat <sha>` |

Срез длиннее ~30 коммитов — отдай его субагенту и возьми выжимку в десять строк.

Коммит: заголовок — что (`fix: даты в одном формате`), тело — почему, трейлер
`--trailer "Requirements: R02"`, если правка касается требования из `manifest.md`.
Заголовок `Autopilot: сборка сдана` пишет только автопилот — по нему находят последнюю сборку.
<!-- autopilot:end -->
