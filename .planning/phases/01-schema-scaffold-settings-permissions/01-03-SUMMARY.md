---
phase: 01-schema-scaffold-settings-permissions
plan: 03
subsystem: i18n
tags: [lang, rainlab-translate, october-cms, locales, scaffold]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: existing en/lang.php (plugin/settings/field/menu blocks); empty lv/no/ru directories
provides:
  - EN lang file with 7 top-level blocks (plugin, settings, field, menu, permission, exception, validation)
  - 4 permission keys + 4 *_comment siblings + tab label resolvable via `Lang::get('logingrupa.goodsreceivedshopaholic::lang.permission.*')`
  - 8 exception keys mirroring PARSE-02 typed-exception class names
  - 4 validation keys for Invoice/InvoiceLine `$rules` messages
  - lv/no/ru lang files as byte-identical EN stubs (RainLab.Translate keyset alignment)
affects:
  - 01-05 (Settings YAML + multisite — fields use `field.*` keys; settings menu uses `menu.*` and `settings.*` keys)
  - 01-06 (Plugin permissions registration — uses `permission.*` keys and `permission.tab` label)
  - 01-07 (Multisite test — references `field.*` and `settings.*` keys)
  - 02-* (Parser/matcher services — throw exceptions referencing `exception.*` keys)
  - 03-* (Apply services — emit log/error messages from `exception.*` keys)
  - 04-* (Backend UI — renders all lang keys in forms, lists, role tabs)
  - 05-OPS-04 (Full translation populate of lv/no/ru with native-language values)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Nested-array lang files: top-level groups (plugin, settings, field, menu, permission, exception, validation) → flat key/value pairs"
    - "Locale stubbing: lv/no/ru created as byte-identical copies of EN to keep RainLab.Translate keyset aligned; translation deferred to OPS-04"
    - "Permission key naming: `<verb>_<noun>` + `<verb>_<noun>_comment` siblings (matches RainLab Backend permission UI conventions)"
    - "Exception lang keys: snake_case mirroring PARSE-02 exception class names (1:1 mapping for throw-site `lang_key` lookup in Phase 2/3)"

key-files:
  created:
    - lang/lv/lang.php
    - lang/no/lang.php
    - lang/ru/lang.php
  modified:
    - lang/en/lang.php

key-decisions:
  - "Existing 14 EN keys (plugin/settings/field/menu) preserved verbatim; new blocks appended in order permission → exception → validation per D-18"
  - "All 4 locale files byte-identical (md5 0a60d4650fb962977cafd023b01cfe0a) — full translation deferred to Phase 5 / OPS-04 per D-19"
  - "Used `cp` for lv/no/ru creation rather than Write — guarantees structural alignment and makes drift detectable via `diff` or md5"
  - "permission.tab label set to 'Goods Received' (not the plugin display name) — concise tab heading in Backend Users → Roles UI"
  - "validation block kept minimal (4 keys) — covers strict rules from Plan 01-02 Invoice/InvoiceLine models (invoice_number_required/unique, ean_invalid_length, qty_must_be_positive_integer)"

patterns-established:
  - "Append-only lang extension: future phases extending lang.php MUST preserve existing blocks unchanged and append new top-level subsections"
  - "Locale stub-then-translate: scaffold all locales identically, populate translations as a dedicated OPS task — keeps keyset aligned at all times"
  - "Lang-key-first exception design: Phase 2/3 services will resolve human messages via lang lookups, never inline strings"

requirements-completed:
  - SCHEMA-08

# Metrics
duration: 1min
completed: 2026-04-29
---

# Phase 01 Plan 03: Lang Scaffold (en/lv/no/ru) Summary

**EN lang file extended with permission/exception/validation blocks (16 new keys); lv/no/ru created as byte-identical EN stubs to align RainLab.Translate keyset, with native-language translation deferred to OPS-04.**

## Performance

- **Duration:** ~1 min (76s wall clock)
- **Started:** 2026-04-29T14:46:39Z
- **Completed:** 2026-04-29T14:47:55Z
- **Tasks:** 3
- **Files created:** 3 (lang/lv/lang.php, lang/no/lang.php, lang/ru/lang.php)
- **Files modified:** 1 (lang/en/lang.php)

## Accomplishments

- EN lang file extended from 14 keys (4 blocks) to 30 keys (7 blocks); all existing keys preserved verbatim
- 8 new permission keys (`permission.tab` + 4 permission labels + 4 `*_comment` descriptions) resolvable for Plan 01-06 `Plugin::registerPermissions()`
- 8 new exception keys mirroring PARSE-02 typed-exception class names (1:1 lang_key alignment for Phase 2/3 throw sites)
- 4 new validation keys covering Invoice/InvoiceLine model `$rules` messages from Plan 01-02
- lv, no, ru lang stubs created as byte-identical copies of EN — all 4 locales share md5 `0a60d4650fb962977cafd023b01cfe0a`
- All 4 lang files pass `php -l` syntax check
- Plan-level verification block (4 checks) all green: locale presence, syntax validity, content identity, top-level keyset

## Task Commits

Each task committed atomically:

1. **Task 1: Extend lang/en/lang.php with permission/exception/validation blocks** — `93026fe` (feat)
2. **Task 2: Create lang/lv/lang.php as EN-stub copy** — `3b8ea01` (feat)
3. **Task 3: Create lang/no/lang.php and lang/ru/lang.php as EN-stub copies** — `7bcba3f` (feat)

_Plan-metadata commit (this SUMMARY.md) follows separately._

## Files Created/Modified

- `lang/en/lang.php` — Authoritative EN lang. 7 top-level blocks: plugin (2 keys), settings (2), field (8), menu (2), permission (9), exception (8), validation (4). 30 keys total.
- `lang/lv/lang.php` — Latvian stub. Byte-identical to EN. Translation deferred to OPS-04.
- `lang/no/lang.php` — Norwegian stub. Byte-identical to EN. Translation deferred to OPS-04.
- `lang/ru/lang.php` — Russian stub. Byte-identical to EN. Translation deferred to OPS-04.

## Decisions Made

- **EN structure preserved verbatim** — Plan stipulated and implementation honored: existing `plugin / settings / field / menu` blocks not retouched; only new `permission / exception / validation` blocks appended.
- **`cp` for lv/no/ru** — Plan recommendation (Task 2 action notes) followed because it (a) guarantees byte-for-byte alignment, (b) makes drift trivially detectable via `diff` / md5 in CI, (c) avoids template/Write-tool indentation drift between the 4 files.
- **Trailing-comma style preserved** — Pint/PSR-12 trailing comma on every array element matches the existing file.
- **Locale set frozen at 4** — Per plugin CLAUDE.md line 14 and Task 3 instructions: en, lv, no, ru. No other locales added.
- **`permission.tab` value = "Goods Received"** — concise tab heading appropriate for Backend Users → Roles UI grouping.

## Deviations from Plan

None — plan executed exactly as written. All 3 tasks ran clean on first attempt; every acceptance criterion green; verification block (4 checks) all green.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required for lang scaffold.

## Next Phase Readiness

- **Plan 01-05 (Settings YAML + multisite):** all `field.*`, `settings.*`, `menu.*` lang keys it needs are now resolvable.
- **Plan 01-06 (Plugin permissions):** all `permission.*` keys (4 labels, 4 comments, tab) resolvable; `Plugin::registerPermissions()` can render the Backend Users → Roles UI without missing-key warnings.
- **Plan 01-07 (Multisite test):** lang keyset is identical across all 4 locales; Multisite-aware backend forms will not log "missing translation key" warnings during tests.
- **Phase 2 (Parser):** all 8 `exception.*` keys exist; PARSE-02 exception classes can attach `lang_key` references that resolve to human-readable messages.
- **Phase 3 (Apply):** all 4 `validation.*` keys exist for `$rules` messages.
- **Phase 5 / OPS-04 (Translation):** deferred work has clear baseline — replace stub English values in lv/no/ru with native-language translations; structure is locked.

## Self-Check: PASSED

Verified after writing SUMMARY:

- `lang/en/lang.php` exists, modified — FOUND
- `lang/lv/lang.php` exists, created — FOUND
- `lang/no/lang.php` exists, created — FOUND
- `lang/ru/lang.php` exists, created — FOUND
- Commit `93026fe` (Task 1) — FOUND in `git log --oneline --all`
- Commit `3b8ea01` (Task 2) — FOUND in `git log --oneline --all`
- Commit `7bcba3f` (Task 3) — FOUND in `git log --oneline --all`

(Self-check command output captured below in the metadata commit.)

---
*Phase: 01-schema-scaffold-settings-permissions*
*Plan: 03*
*Completed: 2026-04-29*
