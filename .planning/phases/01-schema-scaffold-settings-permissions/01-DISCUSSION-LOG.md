# Phase 1: Discussion Log

**Date:** 2026-04-29
**Mode:** smart-discuss `--auto` (autonomous, single-pass)
**Phase:** 01 — Schema, Scaffold, Settings, Permissions

---

## Mode Note

Autonomous mode resolved each gray area by selecting the recommended option (codebase patterns, project CLAUDE.md, Lovata.Toolbox conventions, locked decisions D11-D15). No interactive prompts. User review of CONTEXT.md decisions before plan-phase is the override channel.

---

## Areas Auto-Resolved

### Area 1/7: Migration File Naming
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | Naming convention for new tables? | `create_<table>_table.php` | October Rain convention; matches `plugins/lovata/shopaholic/updates/` |
| 2 | Naming for column-add on existing table? | `update_<table>_add_<column>.php` | Project convention seen across Lovata + Logingrupa plugins |
| 3 | Version key in `updates/version.yaml`? | `1.0.1` | `1.0.0` is scaffold init; bump on first migration set |
| 4 | One migration per table or combined? | One migration per table | SRP; reversible per concern; matches reference plugins |

### Area 2/7: Schema Types & Indexes
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | EAN column type? | `string(13)` (VARCHAR(13)/TEXT cross-driver) | Preserves leading zeros; locked in REQUIREMENTS SCHEMA-02 |
| 2 | EAN index strategy? | Plain b-tree on `ean` | Batch `whereIn` lookup is the only access pattern |
| 3 | Quantity column type? | `unsignedInteger` | Always positive; decimal rejected upstream by `QuantityNormalizer` (PARSE-05) |
| 4 | Enum-like columns (status, match_strategy, active_managed_by)? | `string(N)` not native ENUM | SQLite test driver has no ENUM; cross-driver portable |
| 5 | invoice_number length? | `string(64)` UNIQUE | Room for `Nr_PRO<num>_<country>_<DDMMYYYY>` plus future override suffix |
| 6 | FK cascade on lines/snapshot from invoice? | `cascadeOnDelete()` | Lines and snapshot have no value without parent invoice |
| 7 | FK cascade from offer/product to invoice_line? | NO cascade — keep audit | Audit history must survive offer/product deletes |

### Area 3/7: Settings Model (per D15)
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | Base class? | `System\Models\SettingModel` direct | D15 LOCKED; `CommonSettings` rejected |
| 2 | Multisite implementation? | `MultisiteInterface` + `MultisiteHelperTrait` manual | D15 LOCKED |
| 3 | `settingsCode` value? | `logingrupa_goodsreceivedshopaholic_settings` | Lovata convention: `<vendor>_<plugin>_settings` (lowercase, underscore-separated, NO `.`) — matches `lovata_basecode_settings`, `lovata_toolbox_settings` patterns. Verified against Lovata.Toolbox `MultisiteHelperTrait` cache key requirements |
| 4 | Settings menu category? | `CATEGORY_SHOP` | Lands beside Shopaholic settings; familiar location for ops |

### Area 4/7: Permissions (4 split per SCHEMA-07)
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | Permission key namespace? | `logingrupa.goodsreceived.<action>` | Matches REQUIREMENTS SCHEMA-07 verbatim |
| 2 | Tab grouping in Roles UI? | Single tab `lang.plugin.name` | Clean — 4 keys grouped under one heading |
| 3 | Default role allocation? | None — operator must opt-in per role | Safe default; production-ready |

### Area 5/7: Lang Scaffold (SCHEMA-08)
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | Structure (flat or nested)? | Nested per existing scaffold | `plugin/settings/field/menu` already in `lang/en/lang.php`; preserve |
| 2 | New top-level subsections? | `permission`, `exception`, `validation` | Phase 1 needs permission labels + exception lang keys for typed exceptions arriving in Phase 2 |
| 3 | Locales populated this phase? | EN only; lv/no/ru stubs with EN values | Full populate deferred to OPS-04 (Phase 5) |
| 4 | Naming pattern for permission keys? | `permission.upload_invoices_label`, `permission.upload_invoices_desc` | Mirrors `field.<key>` + `field.<key>_comment` pattern already in scaffold |

### Area 6/7: Test Fixtures (PARSE-07 prep)
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | How many fixtures? | 3 | REQUIREMENTS PARSE-07 + CLAUDE.md call for "3 representative samples minimum" |
| 2 | Selection criterion? | Time-spread (oldest, mid, latest) | Captures any format drift across distributor's 18-month window |
| 3 | Specific files? | PRO026712 (2024-11-28), PRO029691 (2025-07-09), PRO033328 (2026-04-13) | First, middle, last from 15-file sample set |
| 4 | Destination path? | `tests/fixtures/invoices/` | CLAUDE.md spec; hermetic — tests never read outside `tests/` |

### Area 7/7: Test Base Hardening (QA-11)
| # | Q | Recommended (auto-selected) | Rationale |
|---|---|---|---|
| 1 | Add singleton flush hook now? | Yes | Phase 2/3 singletons just plug in; avoid retrofit pain |
| 2 | Hook signature? | `flushPluginSingletons(): void` | Phase 2/3 register their singletons here |
| 3 | Invocation order in `tearDown()`? | Singleton flush → model event flush → `parent::tearDown()` | Ensures singleton state cleared before model cleanup |
| 4 | Phase 1 singletons to flush? | None (no Stores yet) | Hook is empty in Phase 1; populated by Phase 2/3 |

---

## Deferred Ideas

Captured in `01-CONTEXT.md` `<deferred>` section. Quick recap:

- Full lv/no/ru translations — Phase 5 (OPS-04)
- `SettingsAccessor` wrapper — Phase 3 (APPLY-09)
- `ImportAuditService` — Phase 3 (APPLY-10)
- Plugin boot self-check on PHP upload limits — Phase 4 (UI-12)
- Console command — Phase 4 (UI-11)
- Backend controller — Phase 4 (UI-01..10)
- README + runbook — Phase 5 (OPS-01)

---

## Claude's Discretion

5 items deferred to planner judgment (listed in CONTEXT.md `<decisions>`):
- Plugin::boot()/register() registration order
- Whether to use `string(N)` literally or `Schema::enum` driver call (October Rain v4 API surface check)
- MultisiteHelperTrait `boot{TraitName}` magic-method requirements (planner reads trait source)
- Migration ordering (offers extension vs invoice tables)
- PHPDoc `@property` formatting verbosity (both verbose and minimal pass PHPStan level 10)

---

*Discussion log written: 2026-04-29*
*Mode: smart-discuss --auto, single-pass cap honored*
