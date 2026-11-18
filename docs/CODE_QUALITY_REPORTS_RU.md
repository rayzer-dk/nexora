# Где смотреть результаты проверки кода Nexora

GitHub Actions: https://github.com/rayzer-dk/nexora/actions

Все проверки запускаются для push и pull_request в любых ветках. Вручную можно выбрать нужную ветку через Run workflow.

## PHPStan, мёртвый код, типы

Workflow: `.github/workflows/dead-code-audit.yml` (`Nexora dead-code audit`).

После запуска откройте вкладку Summary и прокрутите до Artifacts. Скачайте `nexora-dead-code-findings`, затем откройте `project.txt`. Каждая запись содержит путь к PHP-файлу, номер строки и текст замечания. `fixture.txt` содержит **специально созданные ошибки для тестирования анализатора**, а не найденные проблемы приложения.

На странице Summary показывается общее число кандидатов. Для текущего запуска оно может отличаться от старого отчёта после изменений в коде.

Запуск в CI: `tools/quality/vendor/bin/phpstan analyse -c phpstan.dead-code-project.neon.dist --no-progress --error-format=raw`. Файл создаётся в `var/phpstan-reports/project.txt` **только внутри GitHub runner** и сохраняется в Artifact, а не коммитится в репозиторий.

Дополнительные обязательные проверки: `PHP quality tooling`, `Nexora QA`, `Quality Gate`. Их ошибки и стек вызовов находятся в соответствующем запуске -> Jobs -> шаг с ошибкой -> журнал.

## Twig

Workflow: `.github/workflows/twig-template-qa.yml` (`Nexora Twig template QA`).

Artifact `nexora-twig-qa`: `context.txt` — кандидаты на ошибки передачи переменных из контроллеров и лишние локальные переменные; `syntax.txt` — синтаксический анализ всех шаблонов.

Синтаксические ошибки блокируют сборку. Предположения анализатора контекста **не блокируют** её, потому что Symfony, наследование шаблонов, события и динамическая передача данных могут создавать ложные предупреждения.

## Регрессия и сборка

Workflows `Playwright Smoke`, `Playwright Regression`, `Quality Gate`, `Database runtime QA`, `Production certification gate`, `Build Nexora production package` проверяются отдельно. Зелёный PHPStan audit не означает прохождение этих тестов.

Артефакты двух описанных диагностических workflows сохраняются до 30 дней (при условии, что ограничения хранения аккаунта или репозитория не меньше). Репозиторий не хранит их постоянно. Для долгосрочного архива нужен отдельный процесс.

Важно: `project.txt` — **не список подтверждённо неработающих функций**. Он содержит сообщения PHPStan, в том числе возможные ложные срабатывания для публичных API, расширений, команд, событий и динамически используемых классов. Удаление кода без проверки зависимостей запрещено.
