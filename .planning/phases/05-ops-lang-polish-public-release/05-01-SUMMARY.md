---
phase: 05-ops-lang-polish-public-release
plan: 01
subsystem: i18n
tags: [lang, rainlab-translate, pest, i18n, translation, ops-04]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: lang/en/lang.php scaffold with all 15 top-level keys (translation source authority)
  - phase: 04-orchestrator-controller-console
    provides: full EN string set via UI controllers + override + initial-reset views (123-line EN canonical file)
provides:
  - LV/NO/RU lang.php files at full EN parity (123 lines each, 15 top-level keys, all nested keys present)
  - LangCompletenessTest — Pest 4 structural gate pinning key parity, leaf non-emptiness, placeholder preservation, typed-confirmation literal preservation
  - Domain glossary (D-08) realized in code: invoice/goods received/apply/override/reset terms locked across 3 locales
affects: [phase-05-public-release, future-locales, future-string-additions, rainlab-translate-runtime]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Lang completeness gate via array_keys_recursive comparison + Pest expect()->toBe (readable diff on failure)"
    - "Placeholder-token preservation as a structural test (not a translator hand-check)"
    - "Server-side strict-equality literals (OVERRIDE / RESET) pinned with str_contains assertions per locale"

key-files:
  created:
    - tests/unit/Lang/LangCompletenessTest.php
  modified:
    - lang/lv/lang.php
    - lang/no/lang.php
    - lang/ru/lang.php

key-decisions:
  - "Sample-based English-leftover smoke (3 high-visibility keys per locale) rather than full diff — catches copy-paste-no-translate without forbidding intentional EN tokens like 'EAN' / 'OVERRIDE' / 'RESET' as full-string values where they are correct"
  - "loadLocale() helper uses realpath() before require — fails loudly with a clear locale-name message if any lang file is deleted/renamed (distinguishes structural drift from missing-file regression)"
  - "Test 5 chose field.enabled, permission.upload_invoices, apply.button_now as the smoke keys — high-visibility operator-facing surface, all with words that have natural translations in all 3 target languages"
  - "Wrote LV first per CLAUDE.md project-base-language directive, then NO (Bokmål, distributor industry vocabulary), then RU (1C-style e-commerce vocabulary common to LV/LT Russian operators)"

patterns-established:
  - "Lang parity tests are pure-structural (no DB, no boot) — runtime cheap, hermetic, safe to bundle into make test"
  - "Translation files use `<?php return [...];` — no declare(strict_types=1) (October convention; pure data, no strict_types needed)"
  - "Future locale additions: add lang/{locale}/lang.php + add 3 it() blocks to LangCompletenessTest (key parity + leaves + smoke)"

requirements-completed: [OPS-04]

# Metrics
duration: 5min
completed: 2026-04-30
---

# Phase 5 Plan 01: Full LV/NO/RU Translation + Lang Completeness Gate

**LV/NO/RU lang packs populated to full EN parity (123 lines each) + Pest 4 structural gate (LangCompletenessTest, 9 tests, 629 assertions) preventing key drift, empty leaves, dropped placeholders, and translated typed-confirmation literals.**

## Performance

- **Duration:** 5 min (1 RED commit + 1 GREEN commit)
- **Started:** 2026-04-30T00:49:48Z
- **Completed:** 2026-04-30T00:54:43Z
- **Tasks:** 2 (TDD: RED → GREEN)
- **Files modified:** 4 (3 lang files + 1 new test file)

## Accomplishments

- All four locale lang.php files now 123 lines, identical nested key structure (15 top-level keys × 4 locales)
- 9 new LangCompletenessTest cases pin parity, non-empty leaves, English-leftover smoke, `:placeholder` preservation, OVERRIDE/RESET literal preservation
- Domain glossary (CONTEXT D-08) locked in code: 9 core terms × 3 locales with consistent vocabulary
- Test suite grew 232 → 241 (+9 tests, +629 assertions); `make all` green; phpstan-baseline.neon SHA unchanged at `4b3227fa…91530a`

## Task Commits

1. **Task 1: Add LangCompletenessTest (RED)** — `ced8a85` (test)
   - Created `tests/unit/Lang/LangCompletenessTest.php` with 9 it() blocks
   - 6 of 9 failed pre-translation (parity 3, placeholder 1, typed-hint 1, sample-smoke 1) — RED gate satisfied
   - Helpers: arrayKeysRecursive, loadLocale, assertAllLeavesNonEmptyString, digKey, collectPlaceholderKeys

2. **Task 2: Populate LV/NO/RU to full parity (GREEN)** — `518aaf0` (feat)
   - Replaced 53-line EN-stub files with 123-line natural-language translations
   - All 9 LangCompletenessTest cases now pass
   - `make all` green: pint-test pass, phpstan 0 errors at level 10, phpmd 0 violations, pest 241/241 passed

**Plan metadata:** _(this commit — final SUMMARY + STATE update)_

## Files Created/Modified

- `tests/unit/Lang/LangCompletenessTest.php` (NEW, 242 lines) — 9 Pest 4 it() blocks covering key parity, leaf non-emptiness, English-leftover smoke, placeholder preservation, typed-confirmation literal preservation
- `lang/lv/lang.php` (123 lines, was 53) — full Latvian translation, project-base language per CLAUDE.md
- `lang/no/lang.php` (123 lines, was 53) — full Norwegian Bokmål translation, distributor industry vocabulary
- `lang/ru/lang.php` (123 lines, was 53) — full Russian translation, 1C-style e-commerce vocabulary

## Domain Glossary Applied (D-08)

| EN | LV | NO (Bokmål) | RU |
|----|----|----|----|
| Goods Received | Preču saņemšana | Vareinngang | Приём товаров |
| Goods Received Notes | Preču saņemšanas pavadzīmes | Vareinngangsnotater | Приходные накладные |
| Invoice | Rēķins | Faktura | Счёт |
| Apply | Piemērot | Anvend | Применить |
| Override | Pārrakstīt | Overstyr | Переопределить |
| Reset | Atiestatīt | Tilbakestill | Сброс |
| Stock | Krājumi | Lager | Запас / Склад |
| Settings | Iestatījumi | Innstillinger | Настройки |
| Permission | Atļauja | Tillatelse | Разрешение |
| Quantity | Daudzums | Antall | Количество |
| Status | Statuss | Status | Статус |
| Notes | Piezīmes | Notater | Примечания |
| Country | Valsts | Land | Страна |

Literal tokens preserved verbatim (NOT translated):
- `OVERRIDE`, `RESET` — server-side strict-equality typed-confirmation tokens (D-19 / D-22 / T-04-06-01)
- `EAN`, `.HTM` — industry / file extension proper nouns
- `:extension`, `:size`, `:id`, `:offer_count`, `:product_count` — Lang::get placeholders

## Decisions Made

1. **Wrote LV first** — per CLAUDE.md project-base-language directive ("project base language per project CLAUDE.md"). LV established the reference natural-domain wording, then NO and RU patterned off it (with locale-appropriate idioms, not literal translation).
2. **Sample-based English-leftover smoke (test 5)** rather than full per-key string diff — three high-visibility keys (`field.enabled`, `permission.upload_invoices`, `apply.button_now`) catch copy-paste-no-translate without false-positives on legitimately-shared tokens like `EAN`, `OVERRIDE`, `RESET`.
3. **Domain glossary terms for "Stock"** — used `Krājumi` (LV, "supplies/stocks", more accurate than literal "lager") and `Lager` (NO, standard distributor vocabulary). RU used both `Запас` (in compound terms) and `Склад` (for warehouse-physical context like "Добавлено на склад") because Russian has no single 1:1 mapping for English "stock" — chose contextually-appropriate term per string.
4. **`loadLocale()` realpath assertion** — fails loudly with the locale name when a file is missing, distinguishing structural drift (missing key) from missing-file regression (someone deleted lang/no/lang.php).

## Deviations from Plan

None — plan executed exactly as written. Both tasks (RED + GREEN) followed the action steps verbatim. Verification commands all green on first run; no auto-fixes needed.

## Issues Encountered

None. RED → GREEN transition was clean: 6 RED failures expected, all flipped to PASS once translations populated. No PHPStan / PHPMD / Pint regressions. Baseline SHA unchanged.

## Verification Results

| Gate | Result |
|------|--------|
| `php -l` (all 4 lang files) | No syntax errors detected |
| `make pint-test` | `{"result":"pass"}` |
| `make analyse` (PHPStan L10) | `[OK] No errors` |
| `make phpmd` | (no output = no violations) |
| `make test` (Pest 4) | 241 passed (1666 assertions) |
| `make all` | All gates green |
| `phpstan-baseline.neon` SHA | `4b3227fa…91530a` (unchanged) |
| LangCompletenessTest filtered run | 9 passed (629 assertions) |

## Next Phase Readiness

- **OPS-04 closed.** Lang pack fully populated for {en, lv, no, ru}; RainLab.Translate auto-discovers files (no plugin-side wiring needed beyond standard `Lang::get()` lookups per D-10).
- **Ready for plan 05-02** (PROJECT.md update — OPS-02) and **plan 05-03** (README — OPS-01) to proceed.
- **Test count baseline updated:** 241/241 (was 232/232 at Phase 4 close). Future plans should start from 241.
- **Drift protection in place:** any future PR adding a key to `lang/en/lang.php` without mirroring it in lv/no/ru will fail `make test` — silent UX regressions blocked.

## Self-Check: PASSED

Files created:
- FOUND: tests/unit/Lang/LangCompletenessTest.php

Files modified:
- FOUND: lang/lv/lang.php (123 lines)
- FOUND: lang/no/lang.php (123 lines)
- FOUND: lang/ru/lang.php (123 lines)

Commits:
- FOUND: ced8a85 (test(05-01): add lang completeness test (RED — LV/NO/RU stubs missing keys))
- FOUND: 518aaf0 (feat(05-01): populate LV/NO/RU lang packs to full EN parity (OPS-04))

Test count: 232 → 241 (+9, all green)
Baseline SHA: `4b3227fa…91530a` (unchanged ✓)

---
*Phase: 05-ops-lang-polish-public-release*
*Completed: 2026-04-30*
