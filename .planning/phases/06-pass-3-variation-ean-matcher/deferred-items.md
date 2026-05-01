# Phase 06 — Deferred Items

Pre-existing issues observed during plan execution that fall outside the scope
boundary of the current plan. Tracked here for future cleanup, not addressed
inline (per executor SCOPE BOUNDARY rule — auto-fix only directly-caused
issues).

## Discovered during 06-01 (2026-05-01)

### Pre-existing test-suite failures (125 failed, unrelated to Plan 06-01)

`make test` reports 125 pre-existing failures at HEAD prior to Plan 06-01
changes (re-measured after temporarily moving the new test file aside —
baseline = 125 failed, 125 passed; with Plan 06-01 applied = 125 failed,
130 passed; **delta is +5 passed, 0 added failures**).

Two distinct root causes observed:

1. **MySQL identifier-length collision in `Apply`/`Console`/`Controllers`/
   `Match`/`Models`/`Orchestrator`/`Parser`/`Plugin` test groups.**

   Symptom:
   ```
   QueryException: SQLSTATE[42000]: Syntax error or access violation: 1059
   Identifier name 'logingrupa_goods_received_initial_reset_snapshot_invoice_id_index'
   is too long (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: nc_app_db)
   ```

   Cause: tests are connecting to the live MySQL `nc_app_db` rather than
   the SQLite-in-memory connection forced by `phpunit.xml` (`DB_CONNECTION=sqlite`,
   `DB_DATABASE=:memory:`, `force="true"`). MySQL 5.7 enforces a 64-char
   identifier limit; the auto-generated index name above is 65 chars.

   Likely root cause: October's bootstrap loads `.env` BEFORE phpunit's
   `<env force="true">` block applies, so the live-DB credentials win.
   Or: a prior plan introduced a migration without an explicit short index
   name, expecting SQLite (which has no length limit) and never exercised
   the suite under MySQL.

   Suggested fix path (out of scope for Plan 06-01):
   - Rename the offending index in
     `updates/2026-…_create_initial_reset_snapshot.php` (or wherever it
     lives) to something <=64 chars (e.g. `irs_invoice_id_idx`), OR
   - Audit `tests/GoodsReceivedTestCase::createApplication()` to ensure
     SQLite-in-memory is forced before `bootstrap/app.php` reads `.env`.

2. **Mockery facade-set BadMethodCallException at GoodsReceivedTestCase line 82.**

   Symptom:
   ```
   BadMethodCallException: Received Mockery_…October_Rain_Foundation_Application::make(),
   but no expectations were specified
   ```

   Affects tests that subclass `GoodsReceivedTestCase` and exercise
   `flushModelEventListeners()` after a teardown that already mocked the
   `app` facade. Likely fix: guard `flushModelEventListeners()` against
   the facade root being a Mockery instance, or unmock before the flush.

### Why deferred

- **Not introduced by Plan 06-01.** Confirmed by re-running `make test`
  with the new test file moved aside — failure count identical (125).
- **Plan 06-01 contract** is hermetic: pure-PHP regex helper + 5 it() cases,
  no DB, no October boot. The 5 new tests pass cleanly in isolation
  (verified via `pest .../tests/unit/Support/VariationExtractorTest.php` —
  5 passed (5 assertions), 0.06s).
- **Tiger-Style scope discipline.** Auto-fixing infra drift in unrelated
  migrations / TestCase plumbing exceeds Plan 06-01's surface area and
  would conflate "Pass-3 variation matcher" commits with "fix DB bootstrap".

### Recommendation

Open a separate plan (suggest Phase 07 or a Phase 05 follow-up) titled
"OPS-fix: stabilise plugin test bootstrap (MySQL→SQLite forcing,
GoodsReceivedTestCase Mockery hardening, index-name length audit)".
