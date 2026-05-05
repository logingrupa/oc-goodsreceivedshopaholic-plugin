---
slug: bug1-button-partial-not-found
status: resolved
trigger: "BUG 1 from .planning/HANDOFF-2026-04-30.md — '_button_' partial not found at /invoices/update/{id}"
created: 2026-04-30
updated: 2026-04-30
---

# Debug Session: bug1-button-partial-not-found

## Symptoms

- **Expected behavior:** Opening invoice detail page `/back/logingrupa/goodsreceivedshopaholic/invoices/update/{id}` (e.g. id=5) renders the FormController update page without error.
- **Actual behavior:** October throws `The partial '_button_' is not found.` at `modules/system/traits/ViewMaker.php:97`.
- **Error messages:** `The partial '_button_' is not found.` (System\Traits\ViewMaker line 97). The partial name has a TRAILING underscore — `_button_` — suggesting October prepended `_` to a base name `button_`, OR the codebase passes literal `'button_'`.
- **Timeline:** Surfaced in Phase 5 UAT (2026-04-30). Never worked in this branch — this is the first time the invoice detail page has been opened post-FormController scaffolding.
- **Reproduction:**
  1. `cd /home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic`
  2. Open browser at `/back/logingrupa/goodsreceivedshopaholic/invoices/update/5`
  3. Error renders.
- **Working tree:** clean on `master`, 50+ commits this session. Tests `make all` 242/242 green — bug is template-render-only, not unit-test-covered.

## Investigation Hints (from handoff doc)

```bash
grep -rn "button_\|partial:.*button\|makePartial.*button" controllers/ models/ Plugin.php
```

Likely culprits:
- `controllers/invoices/update.htm` — form-buttons section
- `models/invoice/columns.yaml` — column with `partial: button`
- `models/invoice/fields.yaml` — `formAction` / button field

Fix: correct the partial name OR create missing `_button.htm`.

## Locked Engineering Bar (enforce on every commit)

- `declare(strict_types=1);` every PHP file
- Hungarian notation: `$obItem`, `$arList`, `$iCount`, `$sName`, `$bIsActive`, `$fPrice`
- `final class` on leaf classes; PHPStan level 10 clean; `phpstan-baseline.neon` MUST stay 33 bytes
- Functions <70 lines, max 1 nesting level, guard clauses + early returns
- Twig syntax does NOT work in October backend partials — PHP only
- Minimum diff, no scope creep
- DO NOT add Co-Authored-By Claude to commits

## Locked Decisions (DO NOT REVISIT)

- D11: GitHub repo PUBLIC
- D12: Override-and-reimport = ADD-ON-TOP (no decrement)
- D13: GRN owns `offer.quantity`
- D14: Vendor-inline ImportAuditService (no soft-dep)
- D15: Settings extends SettingModel + Multisite trait

## Current Focus

- hypothesis: confirmed — `controllers/invoices/config_relation.yaml` has `toolbarButtons: ''` (empty string). October's RelationController `evalToolbarButtons()` does `explode('|', '')` which returns a list with ONE empty-string element `['']`, not `[]`. The toolbar partial then iterates and calls `relationMakePartial('button_'.$button)` with `$button = ''` → `'button_'` → October prepends `_` → looks for `_button_.php` which does not exist.
- test: confirmed by reading `modules/backend/behaviors/RelationController.php:761-773` (evalToolbarButtons) + `modules/backend/behaviors/relationcontroller/partials/_toolbar.php:10` (relationMakePartial('button_'.$button)).
- expecting: changing `toolbarButtons: ''` → `toolbarButtons: false` returns `[]` from `evalToolbarButtons()` (line 765-767: `if ($buttons === false) return []`), the foreach loop iterates zero times, no `_button_` lookup happens.
- next_action: apply fix, run `make all`, manually verify in backend, commit.
- reasoning_checkpoint: confirmed root cause via reading October core source.
- tdd_checkpoint: not applicable — pure YAML config typo, no unit-test pin reasonable (template-render path).

## Evidence

- timestamp: 2026-04-30
  source: grep across plugin
  finding: only one literal `button_` hit was in a translation key (`apply.button_now`) — irrelevant. No `partial: button_` references anywhere in plugin yaml/htm.
- timestamp: 2026-04-30
  source: `controllers/invoices/config_relation.yaml` line 6
  finding: `toolbarButtons: ''` — empty string, not `false` and not omitted.
- timestamp: 2026-04-30
  source: `modules/backend/behaviors/RelationController.php:761-773`
  finding: `evalToolbarButtons()`: `if ($buttons === false) return []; elseif (is_string($buttons)) return array_map('trim', explode('|', $buttons));`. Empty string is a string, not `false` — so `explode('|', '')` returns `['']` (PHP behavior).
- timestamp: 2026-04-30
  source: `modules/backend/behaviors/relationcontroller/partials/_toolbar.php:3-13`
  finding: `foreach ($relationToolbarButtons as $button) ... <?= $this->relationMakePartial('button_'.$button) ?>`. With `$button = ''` → renders partial `'button_'` → October prepends `_` → seeks `_button_.php`.
- timestamp: 2026-04-30
  source: `modules/backend/behaviors/relationcontroller/partials/`
  finding: actual partials are `_button_create.php`, `_button_delete.php`, `_button_update.php`, `_button_add.php`, `_button_remove.php`, `_button_link.php`, `_button_unlink.php`. There is no `_button_.php` (and there shouldn't be — `''` is not a valid button name).

## Eliminated

- `controllers/invoices/update.htm` `form-buttons` div — pure HTML wrapper, no partial call.
- `models/invoice/fields.yaml` — no `partial:` references at all.
- `models/invoice/columns.yaml` / `models/invoiceline/columns.yaml` — no `partial:` references.
- `controllers/invoices/_partials/_audit_panel.htm` — clean PHP/HTML, no partial calls.
- `Invoices.php` controller — all `makePartial(...)` calls reference existing files in `_partials/`.

## Resolution

- root_cause: `controllers/invoices/config_relation.yaml` set `view.toolbarButtons: ''` (empty string). October's `RelationController::evalToolbarButtons()` only short-circuits to `[]` when the value is strictly `=== false`; an empty string falls through to `explode('|', '')` which returns `['']` — a list with ONE empty element. The toolbar partial then attempts `relationMakePartial('button_'.'')` → `_button_.php`, which does not exist.
- fix: changed `toolbarButtons: ''` → `toolbarButtons: false` in `controllers/invoices/config_relation.yaml`. The `=== false` branch returns `[]` and the toolbar foreach iterates zero times. Intent (read-only lines audit table — no Add/Delete buttons) is preserved.
- verification: `make all` → 242/242 tests green. Manual browser check at `/back/logingrupa/goodsreceivedshopaholic/invoices/update/5` confirmed the error is gone (operator-side validation).
- files_changed: `controllers/invoices/config_relation.yaml` (one line: `''` → `false`)
