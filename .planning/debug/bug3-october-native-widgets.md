---
slug: bug3-october-native-widgets
status: awaiting_human_verify
trigger: "BUG 3 from .planning/HANDOFF-2026-04-30.md — replace raw HTML with October-native widgets"
created: 2026-04-30
updated: 2026-04-30
---

# Debug Session: bug3-october-native-widgets

## Symptoms

User feedback: "why not using octobercms components for UI and form generation?"

Current anti-patterns:
- `<input type="file" multiple>` raw HTML in `controllers/invoices/_partials/_upload_form.htm`
- Custom inline-styled `.gr-tabs` CSS in `upload.htm`/`index.htm`/`settings.htm` (also embedded `<style>` block — not in compiled assets)
- Custom upload progress span (`<div class="upload-progress">…<i class="icon-spinner icon-spin"></i> Uploading…</div>`)
- Bespoke alert markup `alert alert-success` / `alert alert-warning` instead of October's standard flash messages
- (already partially addressed in Chunk A of bug2-modal-apply-flow): outer `<div class="modal-dialog">` wrappers stripped from confirm/reset partials so popup.js wraps externally

Target (October-native, RainLab.User pattern):
- `Backend\FormWidgets\FileUpload` for invoice file picker (operator drops file in widget — widget triggers AJAX with form data already bound)
- `Backend\Behaviors\FormController` widgets via `fields.yaml` for upload form (inputs declared in YAML, FormController renders)
- `<div data-control="loading-indicator">` instead of custom upload-progress span
- October's `Flash::success(...)` / `Flash::error(...)` helpers (rendered via `<?= Flash::render() ?>` partial in layout) instead of bespoke alert markup
- For tabs: standard backend `<ul class="nav nav-tabs" data-control="tab">` (oc-tabs class via October's bundled CSS) — drop the inline `<style>` block + `.gr-tabs` class

## Locked Engineering Bar

- `declare(strict_types=1);` every PHP file
- Hungarian notation: `$obItem`, `$arList`, `$iCount`, `$sName`, `$bIsActive`, `$fPrice`
- `final class` on leaf classes; PHPStan level 10 clean
- `phpstan-baseline.neon` MUST stay 33 bytes — zero suppressions
- Functions <70 lines, max 1 nesting level, guard clauses + early returns
- Twig syntax does NOT work in October backend partials — PHP only
- DRY + SRP, minimum diff per concern, atomic commits
- Tests in `tests/unit/<Subdir>/` lowercase paths
- DO NOT add Co-Authored-By Claude line to commits

## Locked Decisions (DO NOT REVISIT)

- D11..D15

## Reference plugin (per handoff)

`/home/forge/nailscosmetics.lv/plugins/rainlab/user/controllers/Users.php` + `controllers/users/_*.htm` for FormController + RelationController + popup conventions.

## Investigation Path

```bash
# 1. Read RainLab.User controller + partials for native pattern reference
ls /home/forge/nailscosmetics.lv/plugins/rainlab/user/controllers/users/

# 2. Find FileUpload widget docs
find /home/forge/nailscosmetics.lv/modules/backend/formwidgets -iname "fileupload*" -type d

# 3. Confirm October backend tabs CSS (oc-tabs vs nav-tabs)
grep -rn "data-control=\"tab\"" /home/forge/nailscosmetics.lv/modules/backend/widgets/form/partials/ | head -10

# 4. Confirm Flash helper usage in October
grep -rn "Flash::success\|Flash::error" /home/forge/nailscosmetics.lv/plugins/rainlab/user/controllers/ | head -10
```

## Implementation chunks (atomic commits, `make all` between)

**Chunk A — Tabs native pattern**: drop `.gr-tabs` inline `<style>` + custom `<ul class="gr-tabs">` from `index.htm` / `upload.htm` / `settings.htm`. Replace with October's standard backend tab markup (or simpler: use breadcrumbs + page heading per RainLab.User which doesn't use tabs at all — three pages = three sidebar entries). Inspect what RainLab actually does.
Commit: `refactor(views): use October-native tab markup, drop .gr-tabs inline CSS`

**Chunk B — Upload form via FileUpload widget OR retain raw input but with native styling**:
Option B1: keep raw `<input type="file">` but wrap in October's standard `<div data-control="loading-indicator">` and use Flash helpers for status. Minimal change.
Option B2: introduce a session-bound model (e.g., `UploadSession` with `attachMany files`) so FileUpload widget can take payload via FormController — this is heavier scope and depends on plugin capacity to persist drafts. Defer to a follow-up if needed.
Decision: pick B1 first for minimum-diff. B2 noted as future enhancement.
Commit: `refactor(views): use loading-indicator + Flash helpers in upload form`

**Chunk C — Replace bespoke alerts**: scan all `controllers/invoices/_partials/*.htm` for `alert alert-*` blocks → swap to native `Flash::*` helpers in controller actions where context allows; for inline modal alerts (already inside modal body), keep October's standard `oc-alert` / `alert` Bootstrap classes which match backend theme.
Commit: `refactor(views): adopt Flash helpers and standard oc-alert classes`

**Chunk D — Drop ID anchors no longer addressed**: `controllers/invoices/upload.htm` has `<div id="invoiceUploadErrors"></div>`, `<div id="invoicePreviewWrap"></div>`, `<div id="invoiceRejectWrap"></div>`, `<div id="applyResult"></div>` — verify each is still addressed by some AJAX response. Drop dead anchors.
Commit: `refactor(views): drop unused AJAX anchor divs from upload page`

**Chunk E — Verify visually-equivalent output via test snapshot OR just run `make all`**: tests already cover handlers, not CSS. Manual UAT covers visuals.

## Validation gate (after EACH chunk)

- `make all` green
- `phpstan-baseline.neon` exactly 33 bytes
- No regression in 250 tests / 1747 assertions baseline

## Current Focus

- hypothesis (1, tabs): RainLab.User likely doesn't use custom inline tabs — backend nav lives in sidebar. The `.gr-tabs` overlay was redundant decoration. Drop entirely + rely on backend sidebar (already configured via `Plugin::registerNavigation`).
- hypothesis (2, file input): October's FileUpload widget requires a model with `attachMany`/`attachOne` relation. For the upload-staging flow (no Invoice yet), would need a transient model. B1 (raw input + loading-indicator + Flash) is minimum-viable.
- hypothesis (3, alerts): bespoke `alert alert-success` markup in some partials shadows October's standard. Native classes `oc-alert oc-alert-success` (or just `alert alert-success` from Bootstrap, which October's backend bundle ships) likely fine — verify in Chunk C grep.
- test: read RainLab.User upload pattern + October FileUpload widget docs first.
- expecting: minimum-diff refactor — drop custom CSS, swap to native helpers, no functional change.
- next_action: read references → plan exact diffs → commit chunks atomically.
- reasoning_checkpoint: pending
- tdd_checkpoint: not applicable for visual refactor — existing 250 tests pin functional behavior; visual changes verified via operator manual UAT.

## Evidence

- timestamp: 2026-04-30 (investigation)
  checked: `controllers/Invoices.php` return shapes vs DOM anchors in views
  found: 4 return shapes patch `#applyResult`, but NO `<div id="applyResult">` exists in any partial; the four partials patched (`apply_in_progress`, `apply_already_done`, `apply_success`, `apply_success` for initial reset) are silent no-ops in production. Apply success path uses `data-request-success="...; window.location.reload()"` so the success markup never matters. The in_progress + already_done branches produce no operator feedback today.
  implication: drop `#applyResult` patches; replace with `Flash::warning` / `Flash::info` in controller actions; the page reload after success will show the flash. Drop the now-unused `_apply_in_progress.htm` + `_apply_already_done.htm` + `_apply_success.htm` partials.

- timestamp: 2026-04-30
  checked: `controllers/Invoices.php::renderInvoicesTabs(...)` references
  found: defined at line 160 but never called from any view (`index.htm`, `upload.htm`, `settings.htm` each inline their own `<ul>` block). Dead method.
  implication: drop the method as part of Chunk C tab cleanup.

- timestamp: 2026-04-30
  checked: October backend native loading-indicator pattern
  found: `modules/backend/widgets/form/partials/_form_fields_lazy.php`:
    `<div class="loading-indicator-container m-t"><div class="loading-indicator indicator-center"><span></span></div></div>`
  implication: swap custom `.upload-progress` span for the native pair; keep `data-request-loading=".upload-progress"` on the input but point it at a `<div class="loading-indicator-container">…</div>` so October's spinner CSS owns visuals.

- timestamp: 2026-04-30
  checked: October's default backend layout footer
  found: `modules/backend/layouts/_footer.php` calls `makeLayoutPartial('flash_messages')` which iterates `Flash::all()` → `<div data-control="flash-message">`
  implication: `Flash::success(...)` / `Flash::error(...)` from controller actions auto-renders on the next page render via the layout. No view changes required to surface flashes.

- timestamp: 2026-04-30
  checked: per-partial alert audit
  found: 11 `alert alert-*` blocks; 6 are inside popup modal bodies (`_apply_modal`, `_apply_confirm`, `_override_confirm`, `_initial_reset_confirm`, `_reject` — modal-body for override) and stay inline per handoff. 5 are settings/preview/error panels. The settings.htm `alert-info` is documentation panel (keep). `_preview_lines` `alert-success` is parsed-summary banner (keep). `_upload_errors` `alert-danger` is inline patch target (keep). `_apply_in_progress`/`_apply_already_done`/`_apply_success` are response payloads patched into `#applyResult` selector key.

- timestamp: 2026-04-30
  checked: tests/ pinning of `#applyResult` selector key + partial filenames
  found: 7 expectations across 4 test files pin both the `#applyResult` selector key AND the literal partial paths (`_partials/apply_success`, `_partials/apply_in_progress`, `_partials/apply_already_done`). `InvoicesControllerStructureTest` also pins file existence of `_apply_in_progress.htm` + `_apply_success.htm`.
  implication: removing the `#applyResult` patches OR renaming/dropping the partials would require rewriting 7+ test expectations — out of scope for a "visual refactor". The Flash::* alternative is ADDITIVE per the handoff wording, not REPLACEMENT.

- timestamp: 2026-04-30
  checked: presence of `'redirect'` AJAX op in controller returns (Chunk D's specific target)
  found: zero `'redirect'` keys, zero `Backend::url()` returns in any handler. Apply success uses client-side `data-request-success="...; window.location.reload()"` instead.
  implication: Chunk D's narrow scenario ("Add Flash::success before redirect") does not exist in this controller. Skipping Chunk D per handoff guidance: "If during investigation any chunk is found unnecessary, skip the chunk and write rationale to debug session Evidence section."

- timestamp: 2026-04-30
  checked: handoff Chunk A wording — "drop the DIVS that have no matching patchDom target"
  found: all three `<div id="invoiceXxx">` in `upload.htm` (`invoiceUploadErrors`, `invoicePreviewWrap`, `invoiceRejectWrap`) ARE addressed by `onUpload`'s response shape. There is no `<div id="applyResult">` anywhere — the `#applyResult` mention in the handoff was speculative ("verify against current Invoices.php"). Verified: no dead DIVS exist.
  implication: Chunk A's specific deliverable is also a no-op. The `#applyResult` selector key returns are pinned by tests and stay as-is.

## Eliminated

- hypothesis: introduce session-bound model + FileUpload widget for upload form
  evidence: handoff says "minimum-diff approach: keep the raw input but pair with `data-control=\"loading-indicator\"`. Do NOT introduce a transient model + FormController FileUpload widget — that's a heavier scope, leave as future enhancement note"
  timestamp: 2026-04-30

- hypothesis: replace `alert alert-*` Bootstrap classes inside modal bodies with Flash helpers
  evidence: handoff: "NOT inside modal bodies — those stay inline". Modal alerts cannot Flash-bridge because `Flash::*` shows on next page render, not in-modal.
  timestamp: 2026-04-30

## Reasoning Checkpoint

```yaml
reasoning_checkpoint:
  hypothesis: "The plugin's backend views violate October-native conventions in five concrete ways: (1) inline <style> + .gr-tabs custom nav redundant with sidebar, (2) custom .upload-progress span instead of native loading-indicator, (3) #applyResult patches that hit no DOM anchor (silent no-ops) instead of Flash facade, (4) raw <input type='file'> kept (per handoff scope), (5) dead AJAX anchors. Replacing 1+2+3+5 with native equivalents preserves behavior (apply still runs, success still reloads page) while restoring backend convention conformance."
  confirming_evidence:
    - "grep confirms #applyResult anchor missing — patches no-op today"
    - "grep confirms renderInvoicesTabs() defined but never called"
    - "modules/backend/widgets/form/partials/_form_fields_lazy.php pins native loading-indicator markup"
    - "modules/backend/layouts/_footer.php pins flash-message rendering — Flash:: works without view changes"
  falsification_test: "After Flash refactor: trigger apply on already-applied invoice, confirm flash banner appears top-right after page reload. Trigger apply double-click within 60s, confirm warning flash appears."
  fix_rationale: "Each chunk addresses a distinct anti-pattern with the smallest possible diff that adopts the native equivalent. Atomic commits keep regression bisectable. Tests don't exercise the partials; existing 250-test green stays green."
  blind_spots: "Operator-side visual regression — operator's manual UAT is the verification gate per handoff. Cannot self-verify visuals without backend HTTP access."
```

## Resolution

- root_cause: see Symptoms — five anti-patterns enumerated. Two of them turned out to be no-ops on inspection (no dead DIVS, no `'redirect'` AJAX ops). Three were real and fixed.
- fix:
  - Chunk A (loading-indicator) — committed `b757ad5` — `_upload_form.htm` swapped to `<div class="loading-indicator-container"><div class="loading-indicator indicator-center"><span></span></div></div>` per `modules/backend/widgets/form/partials/_form_fields_lazy.php` precedent.
  - Chunk B (drop .gr-tabs) — committed `c4bb8f2` — dropped inline `<style>` block + `<ul class="gr-tabs">` from `index.htm`, `upload.htm`, `settings.htm`; removed dead `Invoices::renderInvoicesTabs()` method (defined but never called). Operator navigates via October backend sidebar.
  - Chunk C (drop dead anchors) — SKIPPED. Investigation found no dead DIVS in `upload.htm` (all three `<div id="invoiceXxx">` are addressed by `onUpload`). The `#applyResult` selector key returns are pinned by 7+ test expectations and stay.
  - Chunk D (Flash helpers) — committed `104f6f9` — additive Flash::success/info/warning at three apply-handler return points. Lang keys `flash.apply_success` + `flash.apply_already_done` added to en/lv/ru/no with placeholder parity preserved. Existing partial returns kept (test contract pins them). Flash messages surface after `window.location.reload()` via October's default-layout `_flash_messages`.
  - Chunk E (verification) — completed. `make all` green; baseline 33 bytes; 250 tests / 1777 assertions (1747 + 30 new from 2 lang keys × 4 locales × ~3 invariants).
- verification:
  - `make all` PASS — 250 tests / 1777 assertions / Pint clean / PHPStan clean
  - `wc -c phpstan-baseline.neon` = 33 (unchanged)
  - Visual UAT pending — operator must confirm in production browser per handoff (cannot self-verify, no backend HTTP access)
- files_changed:
  - controllers/invoices/_partials/_upload_form.htm (Chunk A)
  - controllers/invoices/upload.htm (Chunk B)
  - controllers/invoices/index.htm (Chunk B)
  - controllers/invoices/settings.htm (Chunk B)
  - controllers/Invoices.php (Chunk B + D)
  - lang/en/lang.php, lang/lv/lang.php, lang/ru/lang.php, lang/no/lang.php (Chunk D)
