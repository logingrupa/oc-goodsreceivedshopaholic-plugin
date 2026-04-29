---
phase: 01-schema-scaffold-settings-permissions
plan: 02
subsystem: database
tags: [eloquent, october-cms, models, phpstan-level-10, validation, idempotency]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: Plan 01-01 created the 3 GRN tables (invoices, invoice_lines, initial_reset_snapshot) with FKs and indexes. This plan wraps them in Eloquent models.
provides:
  - Invoice Eloquent model with 4 STATUS_* constants and self-referential override chain
  - InvoiceLine Eloquent model with 3 MATCH_STRATEGY_* constants and decimal:4 unit_price cast
  - InitialResetSnapshot Eloquent model with $timestamps=false (write-once audit)
  - Full @property PHPDoc blocks on all 3 models (PHPStan level 10 ready)
  - Validation trait + $rules on all 3 models
  - Hard contract for Phase 2/3 services to consume (Invoice::lines, InvoiceLine::invoice, etc.)
affects: [parser, matcher, apply, initial-reset, audit, controller, phase-2, phase-3, phase-4]

# Tech tracking
tech-stack:
  added: []  # No new libraries; uses existing October Rain Model + Validation trait
  patterns:
    - "Bare integer FK pattern (matched_offer_id, prior_product_id) — no cross-plugin belongsTo to Lovata models, preserves audit history when upstream rows are deleted"
    - "Self-referential override chain: belongsTo['overrideOf'] paired with hasMany['overrides'] on the same FK column"
    - "Write-once audit table: $timestamps=false when migration declares only created_at"
    - "Status/strategy enums as public const STATUS_* / MATCH_STRATEGY_* on the model class itself (no separate enum classes)"
    - "Full @property docblocks per migration column for PHPStan level 10 (one line per column, including read-only relations)"

key-files:
  created:
    - "models/Invoice.php — header model, 4 status constants, self-ref override chain, hasMany lines+snapshots+overrides"
    - "models/InvoiceLine.php — line model, 3 match strategy constants, decimal:4 unit_price, belongsTo invoice"
    - "models/InitialResetSnapshot.php — write-once audit, $timestamps=false, belongsTo invoice"
    - ".planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md — QA tooling absence logged"
  modified: []

key-decisions:
  - "Status/match-strategy enum values exposed as public class constants on the owning model (not separate enum types) — closer reach for services and tests, no enum-class proliferation"
  - "matched_offer_id and matched_product_id stored as bare integer FKs — no belongsTo to Lovata\\Shopaholic\\Models\\{Offer,Product}. Phase 3 services resolve via Offer::find() when needed. Avoids cross-plugin coupling and preserves audit when upstream rows deleted"
  - "InitialResetSnapshot::$timestamps = false — table has only created_at; service code (Phase 3) sets it explicitly with injected clock for determinism"
  - "unit_price documented as `string|null` in @property block — October Rain v4 decimal:N cast returns numeric STRING for precision. Phase 2 PriceNormalizer handles float<->string conversion at the boundary"
  - "qty validation uses min:0 (NOT min:1) — distributor HTM occasionally has zero-qty cancelled-line edge cases; parser writes them, apply skips them"

patterns-established:
  - "Eloquent model file shape: declare(strict_types=1) → namespace → use Model+Validation → docblock with full @property + @property-read + @method static + @mixin → class with use Validation; → constants → $table → $rules → $fillable → $casts → $dates → $hasMany/$belongsTo arrays"
  - "Relations declared as October-style ARRAYS (`public $hasMany = [...]`), not method-based — October's HasRelationships trait introspects the arrays"
  - "All property visibility public on $table/$rules/$fillable/$casts/$dates/$hasMany/$belongsTo (October trait introspection requirement); $fillable/$casts left protected per Eloquent default"
  - "Idempotency pattern: UNIQUE index at DB layer (migration) + status='rejected_duplicate' + override_of_invoice_id self-FK chain (D12 add-on-top semantics)"

requirements-completed:
  - SCHEMA-05

# Metrics
duration: 3min
completed: 2026-04-29
---

# Phase 1 Plan 02: Eloquent Models for GRN Tables Summary

**Three Eloquent models — `Invoice`, `InvoiceLine`, `InitialResetSnapshot` — wrapping the GRN persistence contract with strict typing, Validation trait, and full @property docblocks ready for PHPStan level 10.**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-29T14:58:14Z
- **Completed:** 2026-04-29T15:01:22Z
- **Tasks:** 3/3 complete
- **Files modified:** 3 created (models), 1 created (deferred-items log)

## Accomplishments

- **Invoice model:** Full header schema mapped — 19 documented properties, 4 STATUS_* constants (`parsed`, `applied`, `failed`, `rejected_duplicate`), self-referential override chain (`belongsTo['overrideOf']` + `hasMany['overrides']` on `override_of_invoice_id`), `hasMany['lines']`, `hasMany['snapshots']`. Validation rules on `invoice_number` + `status`.
- **InvoiceLine model:** 16 documented properties, 3 MATCH_STRATEGY_* constants (`offer_code`, `product_code_single_offer`, `none`), `belongsTo['invoice']`, `decimal:4` cast on `unit_price`, boolean cast on `applied`, validation rules on `invoice_id`/`ean`/`qty`/`match_strategy`. Deliberately no cross-plugin belongsTo to Lovata Offer/Product (audit-preserving design).
- **InitialResetSnapshot model:** 8 documented properties, `$timestamps = false` (write-once table — only `created_at`), boolean casts on `prior_offer_active`/`prior_product_active`, `belongsTo['invoice']`, validation on `invoice_id`/`offer_id`/`prior_quantity`.
- **PHPStan level 10 readiness:** Every migration column has a corresponding `@property` line. `@property-read` lines for relations. `@method static newQuery/query` for query builder discoverability. `@mixin \Eloquent` for IDE/static-analyzer awareness of inherited methods.
- **Hard contract delivered:** Phase 2/3 services (parser, matcher, apply, initial-reset) can now consume `Invoice::create([...])`, `$obInvoice->lines`, `$obLine->invoice`, `Invoice::STATUS_APPLIED`, `InvoiceLine::MATCH_STRATEGY_OFFER_CODE` etc. — all statically typed.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create Invoice model** — `f6e1e0e` (feat)
2. **Task 2: Create InvoiceLine model** — `56829a2` (feat)
3. **Task 3: Create InitialResetSnapshot model** — `4d36144` (feat)

**Deferred-items log:** `ee2219b` (docs)

## Files Created/Modified

- `models/Invoice.php` — Header table model. 4 status constants. Self-referential override pair on `override_of_invoice_id`. hasMany: lines, snapshots, overrides. Casts: integer counters, boolean `initial_reset_applied`. $dates: `invoice_date`, `parsed_at`, `applied_at`.
- `models/InvoiceLine.php` — Line table model. 3 match-strategy constants. belongsTo invoice. decimal:4 unit_price (string-typed in @property per Laravel cast contract). boolean `applied`. integer FKs for matched_offer_id/matched_product_id (no belongsTo to Lovata models — by design).
- `models/InitialResetSnapshot.php` — Write-once audit model. `$timestamps = false`. belongsTo invoice. Boolean casts on prior_offer_active/prior_product_active. No cross-plugin belongsTo to offer/product (rollback-history-preserving).
- `.planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md` — Logs absence of QA binaries (phpstan/pint/rector/pest/phpmd) at project-root vendor/bin; pre-existing condition not introduced by this plan.

## Relation Graph

```
                       ┌──────────────────────────────────────────┐
                       │            Invoice (header)              │
                       │                                          │
                       │  - STATUS_PARSED                         │
                       │  - STATUS_APPLIED                        │
                       │  - STATUS_FAILED                         │
                       │  - STATUS_REJECTED_DUPLICATE             │
                       │                                          │
                       │  $belongsTo['overrideOf'] ───┐ self      │
                       │  $hasMany['overrides']    ◄──┘ self      │
                       └──┬──────────────────────────┬────────────┘
                          │ hasMany.lines            │ hasMany.snapshots
                          │                          │
                          ▼                          ▼
            ┌─────────────────────────┐  ┌──────────────────────────────┐
            │     InvoiceLine         │  │   InitialResetSnapshot       │
            │                         │  │                              │
            │  - MATCH_STRATEGY_*     │  │  - $timestamps = false       │
            │  - decimal:4 price      │  │    (write-once: created_at)  │
            │                         │  │                              │
            │  belongsTo['invoice']   │  │  belongsTo['invoice']        │
            └─────────────────────────┘  └──────────────────────────────┘

            (No belongsTo to Lovata Offer/Product on either child model
             — bare integer FKs preserve audit when upstream rows deleted)
```

## Decisions Made

- **Status/strategy as class constants vs separate enum classes:** Constants chosen — services and tests reach them via `Invoice::STATUS_APPLIED` without an extra `use` line, and PHP 8 backed enums would have required additional cast configuration on $casts. Constants stay invisible to PHPStan as bare strings, which matches the storage column type (`string(32)`).
- **No belongsTo to Lovata models:** `matched_offer_id`/`matched_product_id`/`offer_id`/`prior_product_id` are integer columns only. This is a deliberate audit-preserving design (per CONTEXT.md D-12 and the threat-model `accept` disposition on relation lazy-loading): if an offer is deleted, the audit row keeps the historical FK value. Services in Phase 3 do the runtime lookup explicitly via `Offer::find($iId)`.
- **`$timestamps = false` on InitialResetSnapshot:** Migration declared only `created_at`. October's default `$timestamps = true` would attempt to write `updated_at` on save and crash the insert. Phase 3 InitialResetService will set `created_at = now()` explicitly with an injected clock for deterministic tests.
- **`unit_price` PHP type as `string|null`:** Laravel/October's `decimal:4` cast returns a numeric string (precision-preserving). Documented honestly in the @property block. Float conversion lives at the Phase 2 normalizer boundary, not in the model.
- **`qty` validation `min:0` instead of `min:1`:** Distributor HTM samples occasionally include zero-qty rows for cancelled lines. Parser writes them; apply service skips them. Forbidding `0` at validation would force the parser to drop rows and lose audit fidelity.

## Deviations from Plan

### Auto-fixed Issues

None — no auto-fixes required during implementation. Plan was executed exactly as specified, with all model contents matching the inline templates verbatim.

### Plan-grep-pattern artifact (not a real issue)

**Task 2 acceptance criterion 11** (`! grep -q "Lovata.*Offer\|Lovata.*Product" models/InvoiceLine.php`) reported a positive match because the model's docblock explicitly mentions `Lovata\Shopaholic\Models\{Offer,Product}` to document WHY those imports are intentionally absent. The real anti-coupling check (no `^use Lovata` directives) passes cleanly. The intent of AC11 — no cross-plugin model imports — is fully met. No code change made.

## Auth Gates

None — pure file authorship task, no external services involved.

## Deferred Issues

**QA tooling absent at project root.** End-of-plan verification step 3 (PHPStan level 10) and step 4 (Pint test) could not execute — `/home/forge/nailscosmetics.lv/vendor/bin/` is empty (no `phpstan`, `pint`, `rector`, `pest`, `phpmd`). This is a pre-existing infrastructure gap unrelated to plan 01-02's changes. Logged to `deferred-items.md` for resolution before Phase 02 (which depends on these gates). All 3 model files pass `php -l` cleanly and were authored to PSR-12 / PHPStan level 10 standards by hand.

## Threat Surface Scan

No new attack surface beyond what the plan's `<threat_model>` already covers. The 3 mitigations (T-01-07 mass-assignment whitelist via `$fillable` + `Validation`, T-01-09 timestamps for repudiation, T-01-08/10/11 accepted residual risks) are all reflected in the implemented code:

- `$fillable` whitelist present and explicit on all 3 models (no `protected $guarded = []`)
- `Validation` trait + `$rules` enforce `max:13` on EAN, `max:64` on `invoice_number`, `min:0` on quantities
- `$timestamps = true` (Eloquent default) on `Invoice` + `InvoiceLine`; `$timestamps = false` only on `InitialResetSnapshot` (deliberate, write-once table)

No threat flags raised.

## Self-Check

```
FOUND: models/Invoice.php
FOUND: models/InvoiceLine.php
FOUND: models/InitialResetSnapshot.php
FOUND: .planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md
FOUND: f6e1e0e (Task 1: Invoice model)
FOUND: 56829a2 (Task 2: InvoiceLine model)
FOUND: 4d36144 (Task 3: InitialResetSnapshot model)
FOUND: ee2219b (deferred-items log)
```

## Self-Check: PASSED
