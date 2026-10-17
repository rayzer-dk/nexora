# Pull request quality review: Nexora (Symfony 8.1, PHP 8.4+)

Review actual changes for bugs, security and regressions, not artificial code style score or PR volume.
Report only actionable high-confidence findings, with file/line, failure scenario and concrete fix.
Verify methods and services exist in this repository; never guess APIs. Examine dependency and call chains.
Flag potential SQL injection, unsafe user input handling, missing authorization, exposed secrets, and unsafe web output.
Check source is UTF-8 without BOM and no invisible Trojan Source or copy-pasted Markdown/AI code placeholders.
Preserve legitimate multilingual strings, language data, third-party licenses, PHPDoc and useful design comments.
Do not recommend blindly removing comments, adding pointless tests, redundant defensive checks or new packages.
Never let a Rector refactor break functional behavior; check full build, tests and relevant integration contracts.
Never treat PHPStan / Rector / source guard success as a substitute for runtime tests or security review.
Honor Symfony dependency injection, module boundaries and multi-store isolation. Check migrations, checkout, queue and frontend behavior.

Avoid repetitive generated comments, duplicate implementations and speculative dependencies. Flag exact file locations; treat duplicate-looking behavior across modules as potentially intentional until verified.
