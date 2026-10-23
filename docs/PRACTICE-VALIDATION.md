# Isolated practice HTTP validation

## Run

From the repository root in PowerShell:

```powershell
& 'C:\xampp\php\php.exe' tests/practice-http.php
```

Use the normal PHP configuration, **not `-n`**: this test needs PDO SQLite and the application dependencies in `vendor/`. An existing assets manifest is required (`resources/manifest.json`, or the Vite build manifest fallback).

## Isolation

`tests/practice-http.php` exercises `Kashf\Application::build()` with Slim PSR-7 server requests, real body parsing, middleware, Twig rendering, password verification, file-backed PHP sessions, and a dedicated SQLite database. It does not use `tests/bridge.php` or load the repository's `config/local.php`.

Each run creates a randomly named root under:

```text
C:\Users\afeyz\AppData\Local\Temp\opencode\practice-http-<random>
```

The fixture root contains copies of `templates/`, `database/`, and the available assets manifest, plus a generated test-only configuration, password hash, SQLite database, logs, and session directory. The source autoloader/application classes are reused. The feature flag is changed only in the generated fixture configuration. No existing development database, configuration, session, or user data is reset or edited. Fixtures are deliberately retained for inspection; their path is printed on success and failure.

## Executed result

Executed successfully with `C:\xampp\php\php.exe tests/practice-http.php`: **29 checks passed**, exit code **0**.

Retained fixture for this run:

```text
C:/Users/afeyz/AppData/Local/Temp/opencode/practice-http-4824ccfa2550262a
```

Exact successful checks:

1. Locked practice redirects to login.
2. Login template renders with copied manifest.
3. Login rejects missing CSRF (419).
4. Real password login succeeds.
5. Enabled empty practice page renders.
6. School math activity (`math-g1-t2`, `school`, `math`) is seeded through the authenticated JSON activities API (201).
7. Missing practice CSRF cannot create a session (419).
8. Invalid practice CSRF cannot create a session (419).
9. Start redirects after persistence (303).
10. Start snapshots at least two eligible school questions.
11. The current question prompt appears in rendered HTML.
12. Hint POST redirects (303).
13. Hint evidence persists in SQLite.
14. Replayed hint token leaves the entire durable state unchanged.
15. Answer POST redirects (303).
16. Correct answer retains hint evidence and exactly one attempt.
17. Replayed answer neither appends attempts nor rotates the token.
18. Logout succeeds (303).
19. Logged-out practice is locked.
20. Real login creates a different session ID.
21. Fresh authenticated session renders practice and resumes the exact durable snapshot.
22. Child `?view=parent` query alone does not open the parent report.
23. Parent context workflow requires CSRF (419).
24. Explicit parent context workflow is accepted (200).
25. Parent workflow renders the report heading.
26. Parent report shows one assisted correct answer.
27. Disabled feature GET returns 404.
28. Disabled feature POST with valid CSRF returns 404.
29. Disabled feature leaves the durable practice state unchanged.

Also executed `C:\xampp\php\php.exe tests/run.php`: exit code **0**, `PASS 52 domain/calendar/backup checks`. That existing suite emitted `IntlCalendar::getTime()` ICU warnings from `src/Calendar.php:26`; its passing result should not be read as warning-free execution.

The installed PHP configuration emitted a startup warning about missing `zip.so`. It did not prevent PDO SQLite, sessions, rendering, or these checks from running. No PHP configuration was changed to suppress it.

## Scope and limitations

- This is in-process HTTP integration validation, **not browser automation**. It verifies response status, redirects, rendered HTML and database evidence, not visual appearance, JavaScript interactions, asset downloads, network transport, actual cookie delivery, SameSite behavior, or mobile layouts.
- The adapter tracks PHP session IDs directly between requests and closes/reopens the file-backed session on every request. It performs real login/logout and session regeneration, but does not emulate a browser cookie jar or restart PHP between requests. Database evidence is reread using fresh PDO connections.
- Only the available manifest is copied, not the asset bundle; the application can resolve asset URLs and render templates, but this does not prove those URLs are served.
- Parent access is tested as a **workflow context**, not a separate authorization boundary. The app uses a shared password; a logged-in child can explicitly select parent context through the existing API. Query-parameter-only access does not change context.
- Disabled-feature 404 is tested while authenticated and with valid CSRF on POST. Global authentication/CSRF middleware may return a login redirect or 419 before the feature route for other requests.
- This run covers one unfinished session and one assisted correct answer. It does not validate session completion, long-term review scheduling, concurrent submissions, multi-learner isolation, adaptation thresholds, backup/restore, or content correctness; those require separate domain/integration tests.

## Actual isolated Chrome browser execution (2026-10-23)

Run `node tests/practice-browser.cjs`. The test uses the Playwright module fallback from `tests/browser.cjs`, installed Google Chrome, and `C:/xampp/php/php.exe -S` on a randomly allocated loopback port. `tests/practice-browser-router.php` builds the real Application against copied templates/database/manifest and a generated config + SQLite database under the approved temp directory. Repository public assets are served with a realpath containment check and executable-file rejection; all other requests go to the isolated Application. No reset script is used and neither `work/development.sqlite` nor `config/local.php` is loaded or modified. The server and Chrome are stopped in `finally`.

Latest completed execution: **64 checks, 61 passed, 3 failed**, exit code **1**, retained fixture `C:/Users/afeyz/AppData/Local/Temp/opencode/practice-browser-dUi2AA` (includes `server.log` and `results.json`). This is an actual browser run, not an inferred result.

Passed: anonymous redirect; real password form login; authenticated school activity creation; numeric and multiple-choice sessions; hint rendering and persistence; exact durable state after reload; correct-answer feedback; solution display; both completed summaries; explicit parent context and parent report. All six tested page states (start, numeric, multiple-choice, both summaries, parent report) had no horizontal overflow at **320, 390, and 1440px**. No uncaught JavaScript errors were observed.

**Product issue for the parent agent:** the parent report's “بازگشت به تمرین” link measures approximately **112 × 21px** at all three widths, below the test's 44 × 44px touch-target criterion. All other measured controls passed. The test deliberately returns nonzero for this issue; no product template/CSS was edited by this browser-test task. Radio targets are assessed through their clickable multiple-choice label containers rather than the inner 18px radio glyph. This is a geometry criterion, not a claim of complete WCAG compliance.

Screenshots: `work/practice-start-{320,390,1440}.png`, `work/practice-numeric-{320,390,1440}.png`, `work/practice-multiple_choice-{320,390,1440}.png`, `work/practice-summary-fraction_of_quantity-{320,390,1440}.png`, `work/practice-summary-fraction_addition-{320,390,1440}.png`, and `work/practice-parent-{320,390,1440}.png`.

Fixture corrections made during execution: seed `math-g1-t1` before selecting fraction addition (the original `math-g1-t2` only makes quantity questions eligible); tolerate the local PHP startup-warning prefix when reading fixture state JSON; wait for DOMContentLoaded navigation rather than full load. An early start-page summary target failure disappeared in subsequent copied snapshots while product work was happening concurrently; the final result above reflects the completed run only. PHP still emits the existing missing `zip.so` startup warning. This browser run does not cover wrong-answer/unknown flows, long-term scheduling, backup/restore, or a full accessibility audit.
