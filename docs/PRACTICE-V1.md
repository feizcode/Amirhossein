# Kashf practice V1 — design and prototype gate

## V1 candidate increment: AI and 32-question bank

The bank now totals 32 original questions, including mixed subtraction, fraction/mixed products, elapsed time and number differences. All remain prototype_checked until a real human reviewer records approval. Release mode (default `practice_allow_prototype_content=false`) filters to approved records with reviewer/date and pauses unapproved unfinished snapshots without deleting evidence. Local pilot explicitly opts into labeled prototype content.

`AiCoach` is wired to hint/solution actions after assistance evidence is committed; replayed actions do not initiate another call. Results are stored in the durable session snapshot. It uses exact privately configured model, low reasoning, approved reference content only, atomic request/spend reservations and stored fallback. The usage table is migrated/exported/backed up; older V3 backups without AI usage remain readable. 111 mock-only AI tests pass; no claim of live model availability or quality is made. MC answers are resolved from option IDs to their verified text before sending.

Latest validation: 66 real Chrome/Playwright checks passed at 320/390/1440px, including numeric/MC forms, summaries, resume and touch sizes; 31 isolated HTTP checks passed including publication gate. Content and durable-review tests also pass. Publication, provider verification, MariaDB deployment and the real-use pilot remain explicit gates in `PRACTICE-RELEASE.fa.md`.

## Deployment schema export correction

`bin/export-sql.php` now exports schedule and practice DDL alongside the base tables. Previously the export inserted migration markers without exporting those tables' CREATE statements. The regenerated `database/001-initial.sql` is for a new empty database only, never an overwrite of existing learner data. MariaDB import remains an environment-specific release check.

## Mobile interface refinement

Practice pages now have dedicated scoped styles (`resources/practice.css`) rather than relying on home-screen selectors scoped under `#kashf`. Choice rows are large selectable targets, keyboard focus is visible, session progress is exposed as a progressbar, and the review list collapses during an active question to reduce distraction. Bundles were rebuilt with Vite. The one-week observation sheet is `docs/PRACTICE-PILOT.fa.md`; it is not a completed real-use pilot.

## Repeated-encounter support and HTTP validation

Support suggestions now require at least three non-independent question encounters across two distinct sessions. They recommend an easier eligible question or eligible prerequisite; unstudied prerequisites are never offered as practice. If no appropriate question is available, the interface suggests a parent-supported example without inventing a difficulty estimate. Suggestions are advisory, not a forced topic switch or diagnosis. This first implementation uses cumulative history; a recent-window policy and dismissal/recovery behavior need pilot refinement.

`tests/practice-http.php` passes 29 isolated in-process Application-route checks: authentication, CSRF, feature switch, real question form, persisted hints/answers, replay rejection, resume after logout/login and parent-workflow access. See `docs/PRACTICE-VALIDATION.md`. This is not a visual mobile/browser validation. No learner database/config was reset.

## Persistence and review increment — current status

This update supersedes earlier session-only limitations below. `Practice` migration 3 stores durable session snapshots (including versioned question content, every attempt and assistance state) and review items in `practice_sessions` / `practice_reviews`. This is an incremental JSON snapshot schema rather than the fully normalized proposal below. The existing learner row serializes transactional writes; rotating tokens reject replayed submissions. An unfinished session resumes after logout with its original selected content. Completed sessions remain available in the factual parent view (`?view=parent`, selected parent workflow required). Counts remain separate from resource progress.

Wrong/unknown/helped encounters create pending review items. First due date is 2 Tehran-calendar days later; independent answers to a different question in the same group advance to 7 then 14 days, and the third independent review completes the item. No available eligible variant leaves the item pending. A due review starts the next session. Stored hints/solutions always work without AI. Backups now write V3 and include both practice tables, retaining restore support for V1/V2.

Checks: `tests/practice-persistence.php` covers new-service resume, replay, assistance, parent counts, similar variants and three review stages; `tests/practice-backup.php` covers backup roundtrip/legacy restore/rejection. These are SQLite checks, not proof of MariaDB concurrency, mobile usability or completed pilot. Full browser validation, normalized skill/question/attempt tables if needed, prerequisite adaptation, content approval/expansion and provider integration/usage budgets remain outstanding. This increment is not the complete V1 release.

## This deliverable

Opt-in Persian server-rendered practice at `/practice-prototype`, linked from the home screen. Enable with `practice_prototype_enabled => true` in private `config/local.php`; default is false. Existing login, CSRF and no-store protections apply. No external requests, credentials, AI usage or changes to existing completion percentages.

The ten original questions in `database/practice-prototype.json` have independently calculated answer checks in `tests/practice-content.php`. **Automated checks are not human content approval.** They are labeled `prototype_checked`, never validated release content. A parent/educator must check wording, age suitability, answer uniqueness, hints and source rights before a child pilot. Keep release publication restricted to a separate `approved` status with reviewer identity/date.

Prototype records live only in the authenticated PHP session: attempts, hints, solution views, current question and summary. Reloads and navigating away resume; logout deletes these records. This limitation is disclosed on-screen. They are not durable learning records, scheduled reviews or backed-up practice data. Those are stage 3, not implemented by this first-step prototype.

## Learning-path connection (prototype update)

Practice now reads `Learning::state()` and limits the bank to exact math/school topics with a recorded activity or completion, using active school-resource topic IDs. It does not fill presumed gaps from a later completed lesson. Most recently logged covered topic is suggested first. Current explicit links: `math-g0-t3` → patterns, `math-g1-t1` → fraction addition, `math-g1-t2` → fraction of quantity. Rectangle perimeter and supplementary pair-counting have no direct verified lesson mapping and are excluded from this path-bound prototype. Uncovered studied topics produce a truthful empty state, not future-topic substitutions. A session is cleared if its questions no longer satisfy recorded-path eligibility. Inventory shortages shorten the session; optional extra questions are not claimed to be harder challenges. No new questions are generated. Numeric “lesson 8” is not used as a frontier because chapter-review indexing is ambiguous. The following original flow description is superseded where it describes unconstrained topic selection.

## Early-curriculum bank expansion

An additional bank, `database/practice-curriculum-extension.json`, adds paired questions for place value, billion, compound time, improper fractions, mixed-number addition and fraction division. These remain automated-checked prototype content requiring human approval. The original ten-question bank remains intact for traceability. The selector's explicit topic links bound both banks to recorded math/school activity, without interpreting “lesson 8” or inferring missing earlier lessons. The page shows question coverage and gaps for recorded topics.

`php bin/practice-coverage.php` is a read-only local diagnostic printing only school-math topic IDs, titles, last activity dates and completion flags. In this working environment it returned an empty list; this is not evidence about a separate deployed database. No progress entries were invented or imported, and no remote database was contacted.

Run `php -n tests/practice-content.php`, `php -n tests/practice-extension.php` and `php -n tests/practice-prototype.php` after expansion. Prototype state and approval limitations remain unchanged.

## Screen and state flow

Home card → duration/topic choice → one question → application grading → feedback → optional hint/retry or stored solution → next → grouped summary. Short practice has two topic questions and one optional challenge; long practice adds an exploratory question. No history-based ability claims. Optional challenge can be skipped without a failure classification. There is no due review history in the prototype.

Wrong answers and “I don’t know yet” keep the question open. Correct on first attempt without a prior hint is independent; later correct answers are grouped as helped/retried, not independent. A solution view without a correct attempt belongs to “Let’s Practice Again.” Viewing a solution after a correct independent answer does not retroactively remove independent credit. Rotating action tokens plus PHP session locking reject stale/double submissions; CSRF tokens independently protect requests.

## Production selection rules

1. Resume an unfinished session before creating another.
2. Select one due review variant from the same skill/group, excluding the original question. If none is approved, leave the review due; never count solution viewing as success.
3. Add one question for 5 minutes or two for 10–15 minutes from the current topic. With insufficient history, use recent lesson suggestions or explicit student selection.
4. Add an optional challenge. Exclude IDs already selected. If inventory is insufficient, shorten honestly rather than duplicate.
5. Only `approved` questions are eligible. Store selected question versions, slots and state durably.
6. Flag prerequisite support only after at least three struggled question encounters across at least two sessions. A single error changes no inferred ability level. Keep this threshold configurable and review in pilot.

## Persistent design for stage 3

- `practice_skills`: subject/topic keys, prerequisite skill relationships.
- `practice_questions`: versioned prompt, format, options, canonical answer, solution, hint, difficulty, group, source/rights, approval identity/date/status.
- `practice_sessions` / `practice_session_questions`: duration, chosen topic, timestamps, current slot, selected version, optional/skipped status.
- `practice_attempts`: session-question, client idempotency key (unique), initial/subsequent submitted answer, result, assistance already received, timestamp. Assistance events must persist before returning a hint/solution. Grade transactionally in PHP; never in AI.
- `practice_reviews`: origin question/skill/group, stage, next Tehran calendar date, encounter evidence. Incorrect/unknown/helped answers upsert a pending item. Start due in 2 days; successful independent similar-question reviews advance to 7 then 14 days, then complete. Struggle resets to 2 days. Never create duplicate active items for the same origin.
- `practice_ai_usage`: request reservation, status, model identifier, day, token usage, currency/cost when known. Avoid storing learner identifiers or free-form child context.

Use MariaDB and SQLite migrations/tests, transactions and uniqueness constraints. Persist across logout/login. Include every new table in explicit backup/export lists, and exercise restoration. Student/parent workflow selection is **not** a separate authorization boundary in this shared-password app; do not imply otherwise. Parent view reports completed sessions, independent/helped counts, due skills and recent activity separately from resource completion percentages.

## AI integration gate (not implemented)

No AI integration currently exists. The configured OpenCode provider catalog lookup for “GPT-6.1” returned no match; that does not establish availability through a future application provider. “GPT-6.1 Sol” and low reasoning effort remain **unverified**. Do not silently substitute a model. Obtain intended provider/base URL and exact ID, verify its model listing and a minimal request supporting the requested reasoning setting before implementation.

Keep credentials in private server configuration only. Supply question, canonical answer and approved solution plus the requested hint/explanation task; no child name, learning history or unrelated records. Define separate hint (no answer reveal) and simple explanation prompts. Hint generation should not be the grading path. Pre-reserve configurable per-day request and spend budgets atomically; deny live calls when pricing is unknown and a spend ceiling cannot be enforced. Record timeouts/errors, cap output/time, release or reconcile reservations, and use stored content on any failure. Live AI defaults off.

## Pilot gate and evaluation sheet

1. Run content and flow tests. Educator reviews all ten questions; record reviewer/date and any corrections before child use.
2. Enable prototype on a private test instance. Check mobile at 360px, keyboard/focus, Persian digits, retries, wrong/unknown responses, optional skip, reload/resume, duplicate POST, CSRF and unauthenticated access. Confirm logout erases this prototype only.
3. Parent observes without instructing: can Amirhossein choose duration/topic, answer, request a hint, retry, finish and explain summary labels? Record actual time, confusing wording and fatigue; make no ability diagnosis.
4. Once model availability is verified, evaluate both tasks for all ten questions against stored references: mathematical correctness, Persian readability, briefness, no answer leakage in hint, no invented facts, supportive tone. Record exact provider/model/settings, output, reviewer and pass/fail. Reject unsafe/inaccurate outputs; retain stored fallback. No live model evaluation has occurred yet.
5. Gate expansion to 30–50 questions on human approval and observed usability. Complete persistent review/parent dashboards/usage limits/backups before a one-week V1 pilot. None of the full release acceptance criteria should be inferred from this prototype alone.
