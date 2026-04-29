# Phase 4: Backend Controller, Upload/Preview/Apply UI, Console - Context

**Gathered:** 2026-04-29
**Status:** Ready for planning
**Mode:** Auto (smart-discuss `--auto`)

<domain>
## Phase Boundary

Operator-facing backend controller wired to October's `Backend\Classes\Controller` with `ListController` + `FormController` + `RelationController` behaviors. Multi-file `.HTM` upload, preview matched/unmatched lines, Apply with confirmation modal + Cache::lock debounce, override-and-reimport UX (D12), initial-reset UX (typed `RESET`), audit history list + detail view, original-file download, console command `goodsreceived:recompute_active_from_stock`, plugin boot self-check.

**In scope:**
- `controllers/Invoices.php` (Backend ListController + FormController + RelationController; thin — calls orchestrators)
- `controllers/invoices/{config_list,config_form,config_relation}.yaml`
- `controllers/invoices/{index,preview,detail,reject}.htm` (backend partials)
- AJAX handlers: `onUpload`, `onApply`, `onOverrideConfirm`, `onInitialResetConfirm`
- Initial-reset checkbox UI gated by `SettingsAccessor::allowInitialReset()` + typed `RESET` confirmation
- Override-and-reimport UX: typed `OVERRIDE` confirmation + warning copy
- `console/RecomputeActiveFromStock.php` registered via `Plugin::register()` → `registerConsoleCommand()`
- Plugin boot self-check: warn if `max_file_uploads<20` OR `upload_max_filesize<10M`
- 4 permission gate tests (QA-10)
- Backend menu entry under SETTINGS (not main nav per D6)
- File attachOne for original HTM (System\Models\File)
- Cache::lock-based debouncing on Apply

**Out of scope:**
- Frontend Twig (this is BACKEND ONLY)
- Theme work
- Public Composer publish (Phase 5)
- README/runbook (Phase 5)
- Multi-currency display (out of scope per PROJECT.md)

</domain>

<spec_lock>
## Locked Requirements (REQUIREMENTS.md)

Phase 4 reqs LOCKED:
- **UI-01..12** — Backend controller, multi-file upload, preview screen, Apply AJAX + Cache::lock, audit history list + detail, original HTM archive, per-import metrics, initial-reset checkbox + RESET confirmation, pre-parse duplicate detection, override-and-reimport UX + OVERRIDE confirmation, console command, plugin boot self-check (lines 60-72)
- **QA-10** — 4 permission gate tests (line 94)

Phase 4 builds on Phase 3 orchestrators (`ParseAndPersistOrchestrator::run` + `runOverride`, `ApplyOrchestrator::apply`, `InitialResetService::reset`, `ActiveFlagService::reconcileAll`).

</spec_lock>

<decisions>
## Implementation Decisions

### Backend Controller (UI-01)
- **D-01:** `controllers/Invoices.php` extends `Backend\Classes\Controller` with three behaviors: `ListController`, `FormController`, `RelationController` (per UI-01).
- **D-02:** Permission gate at controller boundary via `$requiredPermissions = ['logingrupa.goodsreceived.upload_invoices']` (loose check at class-level for "operators can use this controller at all"); fine-grained per-action gates use `BackendAuth::userHasAccess()` inside each `onXxx()` AJAX handler.
- **D-03:** Controller is THIN — validates request, builds DTOs, calls Phase 3 orchestrators. Zero business logic in controller. All transactions live in orchestrators.
- **D-04:** Registered under Backend → Settings menu (not main nav per D6). `Plugin::registerSettings()` already lands the Settings model; controller registration uses `Plugin::registerNavigation()` returning `[]` (no top-nav entry); audit history reachable via Settings → Goods Received → "Open Invoice History" link.
  - **Alternative:** Make the audit history its own Settings entry alongside the Settings model. Cleaner — one Settings entry routes to settings form, another to invoice list/upload UI. Recommended.

### Multi-file Upload (UI-02)
- **D-05:** Use `Backend\FormWidgets\FileUpload` with `mode: file` and `attachMany` mode for `.HTM` accept filter. Action: controller's `onUpload()` AJAX handler iterates uploaded files, calls `ParseAndPersistOrchestrator::run()` per file, collects results in array.
- **D-06:** Server-side accept filter: `.htm` extension via `accept: '.htm,.HTM'` widget config + double-check in handler via `$obFile->getClientOriginalExtension()`. Reject non-HTM with structured error.
- **D-07:** Per-file size limit ≤ 10 MB (matches plugin boot self-check). Total upload ≤ 20 files (matches `max_file_uploads` warning threshold).

### Preview Screen (UI-03, UI-07)
- **D-08:** After upload, controller renders `controllers/invoices/preview.htm` partial showing all parsed Invoice rows (status='parsed') with sortable columns: row_index, ean, product_name_raw, qty, match_strategy, matched_offer (resolved name), override_qty, override_reason.
- **D-09:** Editable cells: `override_qty` (numeric input, defaults to original qty) + `override_reason` (text input, optional). Saved via `RelationController` inline-edit pattern OR a dedicated AJAX handler `onUpdateLine`.
- **D-10:** Per-import summary panel at top: total units to add, offer count, unmatched line count, source filename, parsed_at timestamp.

### Apply Button (UI-04)
- **D-11:** Apply button uses October backend AJAX: `data-request="onApply" data-request-data="invoice_id: {{ invoice.id }}"`. JS via Larajax (vanilla — no jQuery).
- **D-12:** Confirmation modal: total units + offer count + unmatched line count + (if override) "OVERRIDE add-on-top" warning. October's `popup` widget OR custom modal partial.
- **D-13:** `Cache::lock('apply-invoice-' . $iInvoiceId, 60)` debouncing — try-and-fail-fast: if lock cannot be acquired (concurrent click), AJAX returns "Apply in progress; please wait".
- **D-14:** Apply handler:
  ```php
  public function onApply(): array {
      $iInvoiceId = (int) input('invoice_id');
      if (!BackendAuth::userHasAccess('logingrupa.goodsreceived.apply_invoices')) {
          throw new \October\Rain\Auth\AuthException('Forbidden');
      }
      $obLock = Cache::lock("apply-invoice-{$iInvoiceId}", 60);
      if (!$obLock->get()) {
          return ['#flashMessages' => $this->makePartial('apply_in_progress')];
      }
      try {
          $iUserId = (int) BackendAuth::getUser()->id;
          $obResult = app(ApplyOrchestrator::class)->apply($iInvoiceId, $iUserId);
          return ['#applyResult' => $this->makePartial('apply_success', ['result' => $obResult])];
      } finally {
          $obLock->release();
      }
  }
  ```
- **D-15:** Spinner during request via Larajax data-request-loading attribute.

### Pre-Parse Duplicate Detection (UI-09)
- **D-16:** Upload handler EXTRACTS invoice_number from filename pattern (regex `/^Nr_PRO(\d+)_/i`) BEFORE running parse. If `Invoice::where('invoice_number', $sNumber)->where('status', 'applied')->exists()`, render reject screen WITHOUT parsing.
- **D-17:** Reject screen (`controllers/invoices/reject.htm`) shows: invoice_number, prior_applied_at, applied_by user name, stock_added_units, offers_touched, optional Override checkbox.

### Override-and-Reimport UX (UI-10)
- **D-18:** Override checkbox on reject screen. When checked, reveals warning modal:
  ```
  This re-applies the invoice ADDITIVELY on top of the prior apply.
  Stock will be incremented by new line quantities.
  This is NOT a delta calculation. Continue?
  
  Type OVERRIDE to confirm:
  [_____]
  ```
- **D-19:** Confirmation typed-input (case-sensitive) — `OVERRIDE` literal. JS validates client-side; server re-validates on submit.
- **D-20:** Permission gate: `BackendAuth::userHasAccess('logingrupa.goodsreceived.override_invoices')` at AJAX handler boundary.
- **D-21:** On confirm, controller calls `ParseAndPersistOrchestrator::runOverride($sHtmlContent, $sFilename, $iPriorInvoiceId, $iUserId)` → returns new Invoice with `override_of_invoice_id` set + suffix `-OVR-{priorId}` invoice_number. Operator then clicks Apply on the new invoice — runs through `ApplyOrchestrator::apply()` normally (additive math emerges per Phase 3 D-28).

### Initial-Reset UX (UI-08)
- **D-22:** Initial-reset checkbox visible ONLY when `SettingsAccessor::allowInitialReset() === true` AND no prior `Invoice` with `initial_reset_applied=true` exists. Render conditionally.
- **D-23:** When checked, show pre-mutation count: "This will zero out N offers and deactivate M products." Pulled via:
  ```php
  $iOfferCount = Offer::count();
  $iProductCount = Product::count();
  ```
- **D-24:** Typed `RESET` confirmation field. Apply button only enables when typed exactly. Submit calls `InitialResetService::reset($obInvoice)` BEFORE `ApplyOrchestrator::apply()`.
- **D-25:** Permission gate: `logingrupa.goodsreceived.run_initial_reset` at handler boundary.

### Audit History (UI-05, UI-06)
- **D-26:** List view: `controllers/invoices/index.htm` rendered by `ListController`. Columns: invoice_number, status, total_lines, matched_lines, stock_added_units, applied_by_user_id (resolve to user name), applied_at. Filters: status (parsed/applied/failed/rejected_duplicate), country_code. Default sort: `applied_at DESC`.
- **D-27:** Detail view: `controllers/invoices/detail.htm` (or use `FormController::update()`). Tabs:
  - Lines (RelationController on `lines()` relation, read-only)
  - Unmatched Queue (`lines()->whereNull('matched_offer_id')`)
  - Audit (per-import metric panel: units added, offers touched, applied_by, applied_at, override_of pointer if any)
  - Original File (download button — link to `Invoice::find($id)->original_file` System\Models\File attachOne)
- **D-28:** Original HTM archived via `attachOne` on Invoice model. Add to model: `public $attachOne = ['original_file' => \System\Models\File::class];`. Verify Phase 1 model has this; if not, add via `Plugin::boot()` Model::extend hook OR direct edit (since this plugin's Invoice model can be modified directly).

### Console Command (UI-11)
- **D-29:** `console/RecomputeActiveFromStock.php` extends `Illuminate\Console\Command` (or October's `Command` base if available).
- **D-30:** Signature: `protected $signature = 'goodsreceived:recompute_active_from_stock {--chunk=500}';`
- **D-31:** `handle()` calls `app(ActiveFlagService::class)->reconcileAll()`. Prints progress bar via `$this->output->progressStart()`, `progressAdvance()`, `progressFinish()`. Counts offers chunked.
- **D-32:** Exit 0 on success. Exit 1 on uncaught exception (logged via `$this->error()`).
- **D-33:** Registered via `Plugin::register()` → `registerConsoleCommand('goodsreceived:recompute_active_from_stock', RecomputeActiveFromStock::class)`.

### Plugin Boot Self-Check (UI-12)
- **D-34:** In `Plugin::boot()`, add a runtime check (only on backend requests to avoid frontend overhead):
  ```php
  if (App::runningInBackend()) {
      $iMaxUploads = (int) ini_get('max_file_uploads');
      $sUploadMaxSize = ini_get('upload_max_filesize');
      $iUploadMaxBytes = $this->parseIniSize($sUploadMaxSize);
      if ($iMaxUploads < 20) {
          Log::warning('GoodsReceived: max_file_uploads is below 20', [
              'current' => $iMaxUploads, 'recommended' => 20,
          ]);
      }
      if ($iUploadMaxBytes < 10 * 1024 * 1024) {
          Log::warning('GoodsReceived: upload_max_filesize is below 10M', [
              'current' => $sUploadMaxSize, 'recommended' => '10M',
          ]);
      }
  }
  ```
- **D-35:** Helper `parseIniSize(string $sIni): int` parses values like '10M', '512K' to bytes. Place in `Plugin.php` as private static OR in a small util class.

### Permission Tests (QA-10)
- **D-36:** 4 dedicated test files in `tests/unit/Controllers/`:
  - `RequiresUploadPermissionTest.php` — operator without `upload_invoices` cannot POST onUpload
  - `RequiresApplyPermissionTest.php` — operator without `apply_invoices` cannot POST onApply
  - `RequiresOverridePermissionTest.php` — operator without `override_invoices` cannot POST onOverrideConfirm
  - `RequiresInitialResetPermissionTest.php` — operator without `run_initial_reset` cannot POST onInitialResetConfirm
- **D-37:** Test approach: create backend user with NO permissions, then with each specific permission, simulate AJAX call, assert 403/auth exception OR the action runs successfully.

### Tiger-Style + Conventions (carry forward)
- **D-38:** All new files `declare(strict_types=1);`. Hungarian. `final class` where leaf.
- **D-39:** Functions <70 lines. Max 1 nesting. Guard clauses + early returns.
- **D-40:** PHPStan level 10. No `mixed`. Explicit return types.
- **D-41:** Tests in `tests/unit/<Subdir>/` lowercase (Controllers, Console).
- **D-42:** Real DB. SQLite in-memory. Reuse `tests/unit/Apply/ApplyTestCase.php` pattern.
- **D-43:** No jQuery. Vanilla JS + Larajax for any inline scripts. Most UI uses October's built-in form widgets (no JS needed).

### Test Strategy
- **D-44:** Backend controller tests are TRICKY in pure unit mode — need backend auth simulation. Use October's `BackendAuth::login($obUser)` helper. Seed users with permissions in test setUp.
- **D-45:** Console command test: Pest's `artisan('goodsreceived:recompute_active_from_stock')` assertion (Laravel testing helper). Assert exit code 0 + side effects on Offer.active.

### Claude's Discretion
- Whether to use FormController inline-edit OR a custom AJAX handler for `override_qty` per-line edits (D-09)
- Exact partials structure (preview.htm vs preview/index.htm + preview/_line.htm fragments)
- Whether to gate the entire controller class via `$requiredPermissions` array OR via per-action checks
- File attachOne registration timing (Plugin.php boot vs Invoice model directly)

</decisions>

<canonical_refs>
## Canonical References

### Locked Specs
- `.planning/PROJECT.md` — Architecture preview (UI table, "thin controller")
- `.planning/REQUIREMENTS.md` — UI-01..12 + QA-10 specs
- `.planning/ROADMAP.md` — Phase 4 success criteria (8 items)
- `.planning/phases/03-apply-layer-orchestrators/03-CONTEXT.md` — Phase 3 services + orchestrators (used here)

### Phase 1+2+3 Outputs (DEPENDS ON)
- `Plugin.php` (registerSettings + registerPermissions; ADD registerConsoleCommand here)
- `models/{Invoice,InvoiceLine,InitialResetSnapshot,Settings}.php`
- `classes/dto/`, `classes/exception/`, `classes/parser/`, `classes/match/`, `classes/support/`, `classes/apply/`, `classes/orchestrator/`
- `lang/{en,lv,no,ru}/lang.php` (extend EN with controller labels — full populate Phase 5)

### Lovata + October References
- `plugins/lovata/shopaholic/controllers/Categories.php` — backend ListController + FormController pattern
- `plugins/lovata/orders-shopaholic/controllers/Orders.php` — backend AJAX patterns
- October Rain `\Backend\Classes\Controller` — base class
- October `Backend\FormWidgets\FileUpload` — multi-file upload widget
- October `Backend\Behaviors\ListController` / `FormController` / `RelationController`
- `\System\Classes\PluginBase::registerConsoleCommand()` API

### Plugin Boot Self-Check Reference
- `plugins/logingrupa/storeextender/Plugin.php` — example of boot-time runtime checks if exists
- PHP `ini_get()` for runtime config inspection

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- All Phase 3 orchestrators (auto-injected via `app()` IoC)
- `tests/unit/Apply/ApplyTestCase.php` (extend for controller tests)
- `tests/GoodsReceivedTestCase` base (auth, multisite, lifecycle)

### Established Patterns
- Permission keys defined: `logingrupa.goodsreceived.{upload,apply,override,run_initial_reset}_invoices`
- `lang.permission.<key>_label` lang keys exist (Phase 1)
- `SettingsAccessor` for Settings reads (NEVER `Settings::get()` outside accessor — DRY-grep gate enforced)
- Hungarian everywhere; `declare(strict_types=1)`; PHPStan level 10

### Integration Points
- `Plugin::boot()` body currently empty (Phase 1+2+3 didn't subscribe events). Phase 4 adds the boot self-check + console command registration.
- `Plugin::registerNavigation()` MUST return `[]` (per D-04 — Settings menu only).
- `Plugin::registerSettings()` already returns audit-history-targeted entry (Phase 1 plan 01-05 used `class => Settings::class`); Phase 4 may add a SECOND entry for Invoices controller (alternative D-04). Decision: ADD second Settings entry pointing to Invoices controller URL — keeps menu structure clean.

</code_context>

<specifics>
## Specifics

- **No jQuery (project hard rule).** Confirmation modals + typed-input gates use Larajax + vanilla JS event listeners. Inline scripts in partial files; preferred approach: October's `popup` widget which is server-rendered HTML + minimal client JS.
- **Cache::lock value:** `Cache::lock('apply-invoice-' . $iInvoiceId, 60)` — 60s expiry covers worst-case apply duration (200 lines × ~5ms each = 1s; chunked + indexed reads + single tx commit; well under 60s).
- **Override suffix:** `invoice_number` UNIQUE column gets suffix `-OVR-{priorId}` per Phase 3 03-06. UI displays the BASE invoice_number (without suffix) in the audit list — strip suffix in a model accessor or view helper.
- **Initial-reset visibility:** the `Settings.allow_initial_reset` toggle is per-site (multisite). In a multisite environment, the checkbox appears ONLY where the toggle is on.

</specifics>

<deferred>
## Deferred Ideas

- **Inline qty edit on preview screen** (saves clicks) — V2-OP-01 (Phase 5 v2 backlog)
- **Bulk edit unmatched lines** — V2-OP-02
- **Email notifications** on import results — V2-OP-03
- **Backend widget** showing recent imports — V2-OPS-02
- **Initial-reset rollback CLI** (read snapshot + restore) — Phase 5 ops runbook OR future phase
- **Full lang translation** (lv, no, ru) — Phase 5 OPS-04
- **README + runbook** — Phase 5 OPS-01

</deferred>

---

*Phase: 04-backend-controller-upload-preview-apply-ui-console*
*Context gathered: 2026-04-29 (autonomous mode)*
*Smart-discuss `--auto`: 45 decisions captured across 11 areas; 4 items at Claude's discretion*
