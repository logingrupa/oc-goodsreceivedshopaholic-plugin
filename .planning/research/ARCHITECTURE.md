# Architecture Research

**Domain:** OctoberCMS v4 / Lovata Shopaholic plugin — backend HTM invoice import → incremental stock writes
**Researched:** 2026-04-29
**Confidence:** HIGH (every recommendation grounded in concrete file paths in this repo)

## Standard Architecture

### System Overview (validated against PROJECT.md proposal)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Backend UI (October Backend\Classes\Controller + ListController/         │
│             FormController behaviors + Larajax partials)                 │
│ ┌────────────────────────────────────────────────────────────────────┐   │
│ │ Controllers\Invoices  (index | upload | preview/:id | apply/:id)   │   │
│ │   thin: validate → call orchestrator → render partial              │   │
│ └────────────────────────────────────────────────────────────────────┘   │
├──────────────────────────────────────────────────────────────────────────┤
│ Orchestration (single entry-point per workflow — keeps controller thin) │
│ ┌──────────────────────────┐  ┌──────────────────────────────────────┐  │
│ │ ParseAndPersistOrchestr. │  │ ApplyOrchestrator                    │  │
│ │ (parse → resolve nr →    │  │ (status guard → apply → reconcile    │  │
│ │  persist Invoice@parsed  │  │  active flags → status flip)         │  │
│ │  → match EANs → save     │  │                                      │  │
│ │  matched_offer_id)       │  │                                      │  │
│ └──────────────────────────┘  └──────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────────────────┤
│ Pure / single-responsibility services                                    │
│ ┌──────────────┐ ┌──────────────────┐ ┌──────────────┐ ┌───────────────┐│
│ │HtmInvoice    │ │InvoiceNumber     │ │EanMatcher    │ │StockApply     ││
│ │Parser (pure) │ │Resolver (pure)   │ │Service (DB-r)│ │Service (DB-w) ││
│ └──────────────┘ └──────────────────┘ └──────────────┘ └───────────────┘│
│ ┌─────────────────┐ ┌──────────────────────┐ ┌────────────────────────┐ │
│ │ActiveFlagService│ │InitialResetService   │ │SettingsAccessor        │ │
│ │ (DB-w, idempot.)│ │ (one-shot DB-w)      │ │ (read-only, memoized)  │ │
│ └─────────────────┘ └──────────────────────┘ └────────────────────────┘ │
├──────────────────────────────────────────────────────────────────────────┤
│ Persistence — Eloquent (October\Rain\Database\Model)                    │
│ ┌────────────────────────┐ ┌────────────────────────┐ ┌──────────────┐  │
│ │ Models\Invoice         │ │ Models\InvoiceLine     │ │ Models\      │  │
│ │ (header + status)      │ │ (per-EAN, applied flag)│ │ Settings     │  │
│ └────────────────────────┘ └────────────────────────┘ └──────────────┘  │
├──────────────────────────────────────────────────────────────────────────┤
│ Upstream Lovata Shopaholic (read/write touch points — DO NOT subclass)  │
│ ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────────┐  │
│ │ Lovata\Shopaholic\ │ │ Lovata\Shopaholic\ │ │ Lovata\Toolbox\Event\│  │
│ │ Models\Offer       │ │ Models\Product     │ │ ModelHandler         │  │
│ │ (.code .quantity   │ │ (.code .active)    │ │ → cache invalidation │  │
│ │  .active)          │ │                    │ │   side-effects       │  │
│ └────────────────────┘ └────────────────────┘ └──────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities (final, opinionated)

| Component | Responsibility | New file path |
|-----------|----------------|---------------|
| `Controllers\Invoices` | HTTP boundary. Behaviors only. AJAX handlers delegate to orchestrators. | `controllers/Invoices.php` |
| `Classes\Orchestrator\ParseAndPersistOrchestrator` | Parse-side workflow: parse → resolve number → persist Invoice@parsed → batch-match EANs → write `matched_offer_id`/`match_strategy` per line. | `classes/orchestrator/ParseAndPersistOrchestrator.php` |
| `Classes\Orchestrator\ApplyOrchestrator` | Apply-side workflow: lock → guard idempotency → wrap StockApply + ActiveFlag + status flip in **one transaction**. | `classes/orchestrator/ApplyOrchestrator.php` |
| `Classes\Parser\HtmInvoiceParser` | Pure: HTM string → `ParsedInvoice` DTO. Zero IO. | `classes/parser/HtmInvoiceParser.php` |
| `Classes\Parser\InvoiceNumberResolver` | Pure: body-text → number; fallback filename → number; throws `InvoiceNumberMissingException`. | `classes/parser/InvoiceNumberResolver.php` |
| `Classes\Match\EanMatcherService` | One DB read per parse-batch via `Offer::whereIn('code', $arEans)`. Single-offer-product fallback in second query. Returns `array<string ean, array{offer_id:int, strategy:string}>`. | `classes/match/EanMatcherService.php` |
| `Classes\Apply\StockApplyService` | `apply(int $iInvoiceId): ApplyResult`. Iterates matched lines, increments `offer.quantity`, marks line.applied. **Uses model-level updates so OfferModelHandler fires** (cache stays consistent). | `classes/apply/StockApplyService.php` |
| `Classes\Apply\ActiveFlagService` | `reconcile(array $arOfferIds): void`. Reads settings; toggles `offer.active` / cascades to `product.active` for affected rows only. | `classes/apply/ActiveFlagService.php` |
| `Classes\Apply\InitialResetService` | `execute(int $iInvoiceId, int $iUserId): void`. One-shot. Two-phase: (1) emit audit row; (2) chunked model::update so handlers fire. Disabled after first use via Settings flag. | `classes/apply/InitialResetService.php` |
| `Classes\Support\SettingsAccessor` | Single point for reading plugin settings. Memoized per-request. Methods: `isEnabled():bool`, `autoDeactivateOnZero():bool`, `autoActivateOnStock():bool`, `allowInitialReset():bool`. | `classes/support/SettingsAccessor.php` |
| `Models\Invoice`, `Models\InvoiceLine`, `Models\Settings` | Eloquent models on `logingrupa_goods_received_*` tables; `Settings` extends `System\Models\SettingModel`. | `models/Invoice.php`, `models/InvoiceLine.php`, `models/Settings.php` |
| `Console\RecomputeActiveFromStock` | Thin CLI shell that calls `ActiveFlagService::reconcile()` chunked over all offers. | `console/RecomputeActiveFromStock.php` |
| `Classes\Dto\ParsedInvoice`, `ParsedLine`, `ApplyResult`, `MatchResult` | Plain readonly DTOs (PHP 8.4 readonly classes). Cross-layer payloads. | `classes/dto/*.php` |
| `Classes\Exception\*` | Typed exceptions: `InvoiceNumberMissingException`, `DuplicateInvoiceException`, `InvalidEanException`, `ApplyAlreadyDoneException`. | `classes/exception/*.php` |

> The **Orchestrator** layer is the single architectural addition over PROJECT.md. It is what keeps the controller honestly thin (per "DRY + SRP" lock) and gives one place for the transaction boundary, while keeping `StockApplyService` and `ActiveFlagService` independently testable. Without it, transaction logic leaks into the controller (anti-pattern in October backend code).

## Recommended Project Structure

```
plugins/logingrupa/goodsreceivedshopaholic/
├── Plugin.php
├── plugin.yaml
├── composer.json
├── classes/
│   ├── orchestrator/                   # workflow façades — controller's only collaborators
│   │   ├── ParseAndPersistOrchestrator.php
│   │   └── ApplyOrchestrator.php
│   ├── parser/                         # pure
│   │   ├── HtmInvoiceParser.php
│   │   └── InvoiceNumberResolver.php
│   ├── match/                          # DB-read only
│   │   └── EanMatcherService.php
│   ├── apply/                          # DB-write only — side effects isolated
│   │   ├── StockApplyService.php
│   │   ├── ActiveFlagService.php
│   │   └── InitialResetService.php
│   ├── support/
│   │   └── SettingsAccessor.php
│   ├── dto/
│   │   ├── ParsedInvoice.php
│   │   ├── ParsedLine.php
│   │   ├── MatchResult.php
│   │   └── ApplyResult.php
│   ├── exception/
│   │   ├── InvoiceNumberMissingException.php
│   │   ├── DuplicateInvoiceException.php
│   │   ├── InvalidEanException.php
│   │   └── ApplyAlreadyDoneException.php
│   └── event/                          # only if extending upstream models — not planned at MVP
├── models/
│   ├── Invoice.php
│   ├── InvoiceLine.php
│   ├── Settings.php
│   ├── invoice/                        # Backend YAML field/list config
│   │   ├── columns.yaml
│   │   ├── fields.yaml
│   │   └── _preview.htm
│   ├── invoiceline/
│   │   ├── columns.yaml
│   │   └── fields.yaml
│   └── settings/
│       └── fields.yaml
├── controllers/
│   ├── Invoices.php
│   └── invoices/
│       ├── config_list.yaml
│       ├── config_form.yaml
│       ├── config_relation.yaml        # invoice → lines on preview
│       ├── index.htm                   # list of invoices (filter by status)
│       ├── upload.htm                  # multi-file upload form (custom action)
│       ├── preview.htm                 # parsed invoice + matched/unmatched lines + Apply button
│       ├── _toolbar.htm
│       └── _apply_summary.htm          # rendered after Apply via Larajax partial swap
├── console/
│   └── RecomputeActiveFromStock.php
├── lang/
│   └── en/lang.php                     # + lv, ru, no
├── partials/                           # if any cross-controller partials needed
├── updates/
│   ├── version.yaml
│   ├── create_table_invoices.php
│   ├── create_table_invoice_lines.php
│   └── seeder_default_settings.php
├── tests/
│   ├── GoodsReceivedTestCase.php
│   ├── fixtures/
│   │   └── invoices/Nr_PRO*.HTM        # 3 hermetic samples copied from storage/
│   ├── Unit/Parser/HtmInvoiceParserTest.php
│   ├── Unit/Parser/InvoiceNumberResolverTest.php
│   ├── Unit/Match/EanMatcherServiceTest.php
│   ├── Unit/Support/SettingsAccessorTest.php
│   ├── Feature/Apply/StockApplyServiceTest.php
│   ├── Feature/Apply/ActiveFlagServiceTest.php
│   ├── Feature/Apply/InitialResetServiceTest.php
│   ├── Feature/Apply/IdempotencyTest.php
│   └── Feature/Console/RecomputeActiveFromStockTest.php
└── (Makefile, phpcs/phpstan/phpmd/pint/rector configs already exist)
```

### Structure Rationale

- **`classes/orchestrator/`:** the only non-pure layer the controller calls. One file per workflow (parse, apply). Keeps transactions and audit-stamping in one place per workflow — not scattered across services.
- **`classes/{parser,match,apply,support}/`:** mirrors PROJECT.md's "Parse → Match → Apply" with the addition of `support/` for cross-cutting read concerns (SettingsAccessor). Forces the SRP lock.
- **`classes/dto/`:** PHP 8.4 readonly DTOs cross every layer. PHPStan level 10 needs concrete shapes — arrays as boundary types fail under level 10.
- **`models/<name>/`:** matches Lovata convention (see `plugins/lovata/shopaholic/models/offer/columns.yaml`); keeps form/list YAML co-located with the model.
- **`controllers/invoices/`:** mirrors `plugins/logingrupa/storeextender/controllers/groups/` exactly — the QA reference.
- **`tests/Unit` vs `tests/Feature`:** Pest-conventional split. Pure parsers + resolvers + DTOs in Unit (no DB). Anything that touches Eloquent → Feature (real SQLite in-memory).

## Architectural Patterns

### Pattern 1: Static `::instance()` Singletons (NOT App::singleton)

**What:** Lovata-style `static::instance()` accessor on stateless services and stores. Confirmed in `plugins/lovata/toolbox/classes/helper/PriceHelper.php:33` (`self::instance()`) and `plugins/lovata/shopaholic/classes/store/brand/ListByCategoryStore.php:53` (`ProductListStore::instance()`).

**When to use:** for `SettingsAccessor`, the matchers, and any read-only stateless service.

**Trade-offs:**
- (+) Matches the surrounding ecosystem (Lovata Shopaholic uses this pervasively).
- (+) No container resolution overhead (FPM warm path).
- (+) Trivially mockable in tests via `App::instance()` swap when needed.
- (–) Container DI is the Laravel-12 idiom; we deviate. **Acceptable** here because the entire vendored Lovata stack does the same — consistency wins.

**Example:**
```php
final class SettingsAccessor
{
    private static ?self $obInstance = null;
    private array $arCache = [];

    public static function instance(): self
    {
        return self::$obInstance ??= new self();
    }

    public function isEnabled(): bool
    {
        return $this->arCache['enabled']
            ??= (bool) Settings::get('enabled', false);
    }

    /** Test reset hook — called by GoodsReceivedTestCase::tearDown */
    public static function flush(): void
    {
        self::$obInstance = null;
    }
}
```

`App::singleton` was found in **only one** place in the entire codebase (`plugins/lovata/buddies/Plugin.php:60`) — not the ecosystem norm. Use it only if the service genuinely needs container-managed dependencies (none of ours do).

### Pattern 2: Orchestrator + Pure-Service Composition

**What:** Controllers do not call services directly. They call **one orchestrator method** per workflow. Orchestrators compose pure services (`HtmInvoiceParser`, `EanMatcherService`) with side-effecting services (`StockApplyService`, `ActiveFlagService`) and own the transaction boundary.

**When to use:** any workflow that touches more than one service, especially when transactions or rollback semantics matter.

**Trade-offs:**
- (+) Controller stays under PHPMD's `ExcessiveClassComplexity` threshold.
- (+) Transactions live in exactly one place per workflow — easy to reason about.
- (+) Each pure service stays trivially unit-testable (no DB mock setup).
- (–) One additional layer. Worth it because PROJECT.md explicitly forbids "god classes" and forbids fat controllers.

**Example:**
```php
final class ApplyOrchestrator
{
    public function __construct(
        private readonly StockApplyService $obStockApply,
        private readonly ActiveFlagService $obActiveFlag,
    ) {}

    public function apply(int $iInvoiceId, int $iUserId): ApplyResult
    {
        return DB::transaction(function () use ($iInvoiceId, $iUserId): ApplyResult {
            // pessimistic lock — concurrent Apply clicks are real risk in backend UIs
            $obInvoice = Invoice::lockForUpdate()->findOrFail($iInvoiceId);

            if ($obInvoice->status === Invoice::STATUS_APPLIED) {
                throw new ApplyAlreadyDoneException($obInvoice);
            }

            $obResult = $this->obStockApply->apply($obInvoice);
            $this->obActiveFlag->reconcile($obResult->arAffectedOfferIds);

            $obInvoice->status      = Invoice::STATUS_APPLIED;
            $obInvoice->applied_at  = now();
            $obInvoice->applied_by  = $iUserId;
            $obInvoice->save();

            return $obResult;
        });
    }
}
```

### Pattern 3: Backend FormController/ListController Behaviors (October Standard)

**What:** Reuse October's `Backend\Behaviors\ListController` + `FormController` + `RelationController` for the audit history / per-invoice detail. Custom action methods (`upload()`, `preview()`, `apply()`) for the bespoke workflow steps.

**When to use:** any backend CRUD-shaped surface. Confirmed pattern in `plugins/logingrupa/storeextender/controllers/Groups.php` — exactly two-screen list+form flow.

**Trade-offs:**
- (+) Free pagination, search, filtering, CSV export, multi-delete in list view.
- (+) Free YAML-driven form schema (matches Lovata convention).
- (–) Custom `upload`/`preview`/`apply` actions still need their own `.htm` view + AJAX handlers — behaviors do not cover the parse-preview-apply two-step.

**Example skeleton:**
```php
final class Invoices extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
        RelationController::class,
    ];

    public $listConfig     = 'config_list.yaml';
    public $formConfig     = 'config_form.yaml';
    public $relationConfig = 'config_relation.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('System', 'system', 'settings');
    }

    public function upload(): void { /* render upload.htm */ }

    public function onParseUpload(): array
    {
        $arInvoiceIds = ParseAndPersistOrchestrator::instance()
            ->parseAndPersist((array) Input::file('files'));
        return ['#preview-area' => $this->makePartial('parse_summary',
            ['arInvoiceIds' => $arInvoiceIds])];
    }

    public function onApply(int $iInvoiceId): array
    {
        $obResult = ApplyOrchestrator::instance()
            ->apply($iInvoiceId, BackendAuth::getUser()->id);
        return ['#apply-summary' => $this->makePartial('apply_summary',
            ['obResult' => $obResult])];
    }
}
```

## Data Flow

### Request Flow — Parse + Persist (state-persistence answer)

```
POST upload(files[])
   │
   ▼
Controllers\Invoices::onParseUpload  (Larajax handler)
   │
   ▼
ParseAndPersistOrchestrator::parseAndPersist(files[])
   │
   ├─► HtmInvoiceParser::parse(string)         → ParsedInvoice DTO
   │
   ├─► InvoiceNumberResolver::resolve(dto, fn) → string|throw
   │
   ├─► DB::transaction:
   │     Invoice::create(status='parsed', counters=null, applied_at=null)
   │     InvoiceLine::insert(many, matched_offer_id=null)
   │
   ├─► EanMatcherService::matchBatch(arEans)   → array<ean, MatchResult>
   │
   └─► InvoiceLine::query()->whereIn('id',...)->update([matched_offer_id, match_strategy])
   │
   ▼
Larajax partial swap → preview UI rendered server-side from DB
```

### Request Flow — Apply

```
POST onApply(:invoice_id)
   │
   ▼
ApplyOrchestrator::apply(iInvoiceId, iUserId)
   │
   └─► DB::transaction:                          ◄── ONE transaction wraps everything
         Invoice::lockForUpdate()->find()
         guard: throw ApplyAlreadyDoneException if status=applied
         StockApplyService::apply(obInvoice):
           foreach matched lines:
             $obOffer = Offer::find(line.matched_offer_id)
             $obOffer->quantity += line.qty
             $obOffer->save()                    ◄── triggers OfferModelHandler
             line.applied_qty = line.qty; line.applied = true; line.save()
         ActiveFlagService::reconcile(arAffectedOfferIds):
           foreach offer:
             apply auto_deactivate_on_zero / auto_activate_on_stock per Settings
             if any toggled, also reconcile parent Product.active
         invoice.status='applied'; invoice.applied_at=now; invoice.applied_by=user; save()
   │
   ▼
ApplyResult DTO → Larajax partial → success summary
```

### State Management — answer to question 1

**Recommendation: Option A (persist Invoice + InvoiceLine rows with `status='parsed'`).**

**Rationale:**

| Criterion | Option A — DB persist | Option B — session/cache | Option C — JSON blob |
|---|---|---|---|
| Survives operator browser refresh | Yes | No (session) | Partial |
| Audit trail of every parse attempt | Yes | No | Yes |
| Reuses ListController for unmatched-EAN queue | Yes | No | No (lines not queryable) |
| FormController preview rendering | Trivial — `$obInvoice->lines` relation | Requires custom session→array→partial code | Requires JSON unpacking |
| Idempotency check before persist | DB unique index does it for free | Manual session bookkeeping | Manual JSON walk |
| Multiple operators uploading concurrently | Safe (per-row) | Session-scoped pollution | Session-scoped pollution |
| Cleanup of abandoned parses | Cron / scheduled `Invoice::where('status','parsed')->where('created_at','<', now()->subDays(7))->delete()` | Session GC (uncontrolled) | Cron on the same row |
| PHPStan level 10 compatibility | Easy (typed relations) | Hard (mixed session payload) | Hard (mixed JSON payload) |
| Partial-apply restart after server-side error | Yes (resume from `status='parsed'`) | No | Partial |
| Test determinism | High (SQLite) | Low (session driver dependent) | Medium |

**Plus:** Option A is the **only** option compatible with October's `RelationController` — which is the cheapest way to render the matched/unmatched lines in the preview UI without writing custom Twig.

**Failure mode handled:** if Apply fails mid-transaction, the invoice stays at `status='parsed'`, allowing retry. Sessions do not give you that.

### Key Data Flows

1. **Parse → preview:** files → DTOs → Invoice@parsed + lines → batch-match → preview UI. Survives refresh.
2. **Apply → reconcile:** Invoice@parsed → locked transaction → per-offer increments → active-flag reconcile → Invoice@applied. Single rollback unit.
3. **Initial reset → bootstrap:** Settings flag → `InitialResetService::execute()` → audit Invoice@reset row → chunked `Offer::query()->update(['quantity'=>0,'active'=>false])` (uses model events) → `Product` cascade. Flag flips off in `afterReset` hook so it cannot run twice.
4. **Console reconcile:** `goodsreceived:recompute_active_from_stock` → `Offer::chunk(500, fn ...)` → `ActiveFlagService::reconcile($arOfferIds)` per chunk. No invoice involved.

## Service injection — answer to question 2

**Recommendation: static `::instance()` singletons. NOT `App::singleton`. NOT constructor-injected into controllers.**

| Reason | Evidence |
|---|---|
| Ecosystem consistency | `App::singleton` appears **once** in `plugins/lovata/buddies/Plugin.php:60` across all of Lovata + Logingrupa. Static `::instance()` appears in dozens of stores/helpers. |
| October Backend\Classes\Controller does not use container injection in constructor | All `controllers/*.php` examples in the repo use `parent::__construct()` only and pull collaborators inside action methods. |
| FPM warm-path overhead | Container resolution is non-zero. Static `::instance()` is one `??=` per request. |
| Test ergonomics | `Service::instance()` can still be replaced with a test double via reflection or via a `static $obInstance = null; flush()` reset hook called from `GoodsReceivedTestCase::tearDown()`. |
| PHPStan level 10 friendliness | Static factory returns `self` — fully typed, no container generic erasure. |

Implementation rule: every singleton service exposes a `public static function flush(): void` that nulls its static `$obInstance` and clears any memo arrays. `GoodsReceivedTestCase::tearDown()` already calls `flushModelEventListeners()` per CLAUDE.md — extend it to call `SettingsAccessor::flush()` and any other singleton's flush. Without this you get cross-test bleed in Pest's parallel runner.

## Stock-write transaction boundaries — answer to question 3

**Recommendation: ONE transaction wrapping the ENTIRE apply (lines + status flip + active-flag reconcile).**

```php
DB::transaction(function () use ($iInvoiceId, $iUserId): ApplyResult {
    // 1. lock
    $obInvoice = Invoice::lockForUpdate()->findOrFail($iInvoiceId);

    // 2. guard
    if ($obInvoice->status === Invoice::STATUS_APPLIED) {
        throw new ApplyAlreadyDoneException($obInvoice);
    }

    // 3. stock writes (model::save so OfferModelHandler fires + cache invalidates)
    $obResult = $this->obStockApply->apply($obInvoice);

    // 4. active-flag reconcile (same transaction — see "why" below)
    $this->obActiveFlag->reconcile($obResult->arAffectedOfferIds);

    // 5. status flip + audit
    $obInvoice->status     = Invoice::STATUS_APPLIED;
    $obInvoice->applied_at = now();
    $obInvoice->applied_by = $iUserId;
    $obInvoice->save();

    return $obResult;
});
```

**Why one transaction, not per-line:**
- A partial apply leaves stock in an inconsistent state with the audit invoice (some lines applied, some not, but `invoice.status` may not reflect that). Rollback recoverability is the priority.
- Tiger-Style "Safety > Performance > Features" lock — single-transaction rollback is the safe default.
- Per-line transactions would require a sweeper to re-attempt failed lines and reconcile the audit row. Not worth the complexity at MVP.

**Why ActiveFlagService inside the same transaction (not post-commit event):**
- The active-flag toggle is **derived from the new quantity**. If reconcile runs post-commit and another request reads quantity in between, it sees stock-yes-but-offer-still-inactive — the exact bug the feature exists to prevent.
- Reconcile only touches the offers we actually wrote to (`$arAffectedOfferIds`), so the lock surface is bounded.
- `OfferModelHandler::checkActiveField` (line 139 of `plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php`) already invalidates `OfferListStore::instance()->active->clear()` and propagates to product cache — those invalidations happen on save inside the transaction; the cache is rebuilt lazily on next read after commit. No stampede if we keep the transaction short.

**Concurrency risk:** two backend operators clicking Apply on the same invoice. `Invoice::lockForUpdate()` serializes them. The second one sees `status='applied'` and throws `ApplyAlreadyDoneException`. Test this in `Feature/Apply/IdempotencyTest.php`.

**MySQL gotcha:** the production DB engine is InnoDB (confirmed in `create_table_offers.php:24`). `lockForUpdate()` works as expected. SQLite in-memory used for tests does not have row-level locks but the test single-threaded execution covers the logic path.

## Settings model integration — answer to question 4

**Recommendation: extend `System\Models\SettingModel` directly (NOT `Lovata\Toolbox\Models\CommonSettings`). Wrap reads in `SettingsAccessor`.**

**Why direct `SettingModel` (mirror PostNord, not metapixel):**
- `plugins/logingrupa/postnordshippingshopaholic/models/Settings.php` is the QA reference — it extends `System\Models\SettingModel` directly with strict types and `@override`.
- `Lovata\Toolbox\Models\CommonSettings` (`plugins/lovata/toolbox/models/CommonSettings.php`) adds the `Multisite` trait + `RainLab.Translate.Behaviors.TranslatableModel` + `Artisan::call('queue:restart')` on every save (via parent `SettingModel::settingAfterSave`, lines 82–92 of `modules/system/models/SettingModel.php`). The translate behavior is dead weight for stock automation toggles.
- The queue restart on save is **inherited regardless** — it is in the `SettingModel` parent. It is acceptable here (settings change infrequently) but worth flagging in PITFALLS.md.

**Per-site behavior (confirmed):**
- Each site (.no/.lv/.lt) is a separate database. `SettingModel` writes to the local DB's `system_settings` table keyed by `settingsCode`. There is **no cross-DB synchronization possible** — three deploys = three independent settings rows. PROJECT.md "no shared state assumed" is correct.
- Cache key from `getCacheKey()` (line 217 of `SettingModel.php`) is `system::setting.<settingsCode>` — local file/Redis cache, naturally per-server. No collision risk.

**The DRY accessor (resolves PROJECT.md's "no scattered Settings::get calls" rule):**

```php
final class SettingsAccessor
{
    private static ?self $obInstance = null;
    /** @var array<string, bool> */
    private array $arCache = [];

    public static function instance(): self
    {
        return self::$obInstance ??= new self();
    }

    public function isEnabled(): bool
    {
        return $this->bool('enabled');
    }

    public function autoDeactivateOnZero(): bool
    {
        return $this->bool('auto_deactivate_on_zero');
    }

    public function autoActivateOnStock(): bool
    {
        return $this->bool('auto_activate_on_stock');
    }

    public function allowInitialReset(): bool
    {
        return $this->bool('allow_initial_reset');
    }

    private function bool(string $sKey): bool
    {
        return $this->arCache[$sKey]
            ??= (bool) Settings::get($sKey, false);
    }

    public static function flush(): void
    {
        self::$obInstance = null;
    }
}
```

**Lint enforcement:** add a PHPMD/Rector custom rule (or grep in CI) that `Settings::get(` only appears inside `SettingsAccessor.php`. Anywhere else = build failure.

## Initial reset cascade safety — answer to question 5

**Recommendation: trigger model events (use `Offer::query()->chunk(500, fn ...)` then `$obOffer->save()` per row) — DO NOT bypass with `DB::statement`.**

### What fires when you save an offer

`OfferModelHandler` (`plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php`) on every `model.afterSave`:
- Line 47 → `parent::afterSave()` → `clearItemCache()` → `OfferItem::clearCache($id)`
- Line 49 → `checkProductIDField()` (skipped, no change)
- Line 50 → `checkActiveField()` — if active changed:
  - `clearProductActiveList()` → `ProductListStore::instance()->active->clear()` (one call, idempotent)
  - `OfferListStore::instance()->active->clear()` (one call)
  - `clearProductSortingByPrice()` (one call per price type)
  - `clearProductItemCache($product_id)`
  - `clearCategoryItem` (`obCategoryItem->clearProductCount()`)
- Line 52 → `if ($this->isFieldChanged('site_list')) clearCachedListBySite();` (skipped)

Per offer: ~8–12 cache `forget()` calls. For 10k offers in a row: **80k–120k cache writes**. With Redis this is fine (ms range). With file cache this is 80k tiny file writes — slow but not fatal. For nailscosmetics the deploy uses file cache by default (per CLAUDE.md "Redis optional (file cache/sessions default)") so we should chunk.

### Why bypassing events (`DB::statement`) is wrong here

Bypassing events means:
- Cached `OfferItem` snapshots in the `cms_theme_data` cache and `system_files` cache stay stale until next `bust`.
- `ProductListStore::instance()->active` returns the pre-reset list — frontend keeps showing now-deactivated offers as active until cache TTL expires (24h default per Lovata convention).
- Frontend → backend mismatch is the exact category of bug the feature is meant to **prevent**.

### Recommended implementation

```php
final class InitialResetService
{
    public function execute(int $iInvoiceId, int $iUserId): void
    {
        if (!SettingsAccessor::instance()->allowInitialReset()) {
            throw new InitialResetNotAllowedException();
        }

        DB::transaction(function () use ($iInvoiceId, $iUserId): void {
            // 1. audit invoice (status='reset', no lines)
            $obInvoice = Invoice::find($iInvoiceId);
            $obInvoice->reset_executed_at = now();
            $obInvoice->reset_executed_by = $iUserId;
            $obInvoice->save();

            // 2. zero offer quantity + deactivate, chunked, model events fire
            Offer::query()->chunk(500, function ($obOffers): void {
                foreach ($obOffers as $obOffer) {
                    $obOffer->quantity = 0;
                    $obOffer->active   = false;
                    $obOffer->save();   // ← OfferModelHandler invalidates caches
                }
            });

            // 3. deactivate products (cascade)
            Product::query()->chunk(500, function ($obProducts): void {
                foreach ($obProducts as $obProduct) {
                    $obProduct->active = false;
                    $obProduct->save(); // ← ProductModelHandler invalidates caches
                }
            });

            // 4. flip the flag off — one-shot
            Settings::set('allow_initial_reset', false);
        });
    }
}
```

**Cache stampede mitigation:** chunk size 500 keeps each transaction's lock window short. Each chunk releases InnoDB locks. The `OfferListStore::active->clear()` calls **are idempotent** (line 137 of `Lovata\Toolbox\Classes\Store\AbstractStoreWithoutParam::clear`) — calling it 10k times is one cache miss + 9999 forgets of an already-empty key. Negligible.

**Memory safety:** chunk(500) means peak memory ~500 Eloquent models. PHP 8.4 + OPcache: well under 256MB.

**Testing:** `Feature/Apply/InitialResetServiceTest.php` asserts (a) all offer.quantity=0, (b) all offer.active=false, (c) all product.active=false, (d) settings flag flipped, (e) running again throws.

## EanMatcherService DB-read shape — answer to question 6

**Indexes (confirmed from `plugins/lovata/shopaholic/updates/create_table_offers.php` and `create_table_products.php`):**
- `lovata_shopaholic_offers.code` — single-column index (line 41 of `create_table_offers.php`)
- `lovata_shopaholic_products.code` — single-column index (line 39 of `create_table_products.php`)

Both indexes are non-unique (per migration). EANs are typically unique in real data but the schema allows duplicates. Code must handle multi-match defensively.

**Recommended shape — two queries, no join:**

```php
final class EanMatcherService
{
    /**
     * @param list<string> $arEans
     * @return array<string, MatchResult>  keyed by EAN
     */
    public function matchBatch(array $arEans): array
    {
        if ($arEans === []) {
            return [];
        }

        $arResult = [];

        // Pass 1: offer.code (primary)
        $arOfferRows = Offer::query()
            ->whereIn('code', $arEans)
            ->get(['id', 'code', 'product_id'])
            ->groupBy('code');

        foreach ($arOfferRows as $sCode => $obGroup) {
            if ($obGroup->count() > 1) {
                continue; // ambiguous — fall through to unmatched
            }
            $obRow = $obGroup->first();
            $arResult[(string) $sCode] = new MatchResult(
                iOfferId:   (int) $obRow->id,
                sStrategy:  MatchResult::STRATEGY_OFFER_CODE,
            );
        }

        // Pass 2: product.code fallback for unmatched EANs only
        $arUnmatched = array_values(array_diff($arEans, array_keys($arResult)));
        if ($arUnmatched === []) {
            return $arResult;
        }

        // Sub-query: products with exactly one offer
        $arProductRows = Product::query()
            ->whereIn('code', $arUnmatched)
            ->withCount('offer')
            ->having('offer_count', '=', 1)
            ->with(['offer:id,product_id'])
            ->get(['id', 'code']);

        foreach ($arProductRows as $obProduct) {
            $obOffer = $obProduct->offer->first();
            if ($obOffer === null) {
                continue;
            }
            $arResult[(string) $obProduct->code] = new MatchResult(
                iOfferId:   (int) $obOffer->id,
                sStrategy:  MatchResult::STRATEGY_PRODUCT_CODE_SINGLE_OFFER,
            );
        }

        return $arResult;
    }
}
```

**Index plan:** both queries hit the indexed `code` column with a `WHERE IN` — MySQL InnoDB index range scan, O(log N) per EAN. For 200-line invoices this is ~2ms total. No index changes needed on upstream tables.

**No join because:** the join would require `LEFT JOIN offers ON offers.product_id = products.id GROUP BY products.id HAVING COUNT(offers.id) = 1` — slower than two queries because the second query only runs for unmatched EANs (typically 5–10% of lines). Two queries also keep query planner happy on PHPStan-typed return shapes.

**Validate before query:** `InvalidEanException` thrown if any EAN is not 13 digits — keeps the WHERE IN clean.

## Console command vs queue — answer to question 7

**Recommendation: synchronous CLI command, chunked — NO queue.**

**Why sync:**
- It's a **read-rare, write-once** maintenance command. Runs interactively after a settings change, not on every request.
- Lovata's existing imports (`storeextender:sqlimport`, `shopaholic:import_from_xml`) are all synchronous CLI commands. Same pattern.
- Adding a queue means adding a worker, queue table, dead-letter handling — disproportionate complexity.

**Performance shape for 10k offers:**
- chunk(500) × 20 chunks × ~10ms/offer (model::save + model handler cascade) = ~100s for full reconcile.
- Memory: ~500 Eloquent models in peak = ~50MB. Comfortable.
- Acceptable for a manual "reconcile" command run by an operator who can wait 2 minutes.

**Implementation:**

```php
final class RecomputeActiveFromStock extends Command
{
    protected $signature = 'goodsreceived:recompute_active_from_stock {--chunk=500}';
    protected $description = 'Reconcile offer.active and product.active flags from current stock per Settings.';

    public function handle(): int
    {
        $iChunk = (int) $this->option('chunk');
        if ($iChunk < 1 || $iChunk > 5000) {
            $this->error('chunk must be between 1 and 5000');
            return self::INVALID;
        }

        $iTotal = Offer::query()->count();
        $obBar  = $this->output->createProgressBar($iTotal);

        Offer::query()->chunk($iChunk, function ($obOffers) use ($obBar): void {
            $arIds = $obOffers->pluck('id')->all();
            ActiveFlagService::instance()->reconcile($arIds);
            $obBar->advance(count($arIds));
        });

        $obBar->finish();
        $this->newLine();
        $this->info("Reconciled {$iTotal} offers.");
        return self::SUCCESS;
    }
}
```

**If queue ever becomes necessary** (10x growth in catalog → >100k offers): refactor to dispatch one job per chunk. The chunked sync version is forward-compatible — `Bus::dispatch(new ReconcileChunk($arIds))` swaps in cleanly.

## Multi-site safety — answer to question 8

**Recommendation: nothing in plugin design leaks state across sites IF we follow these rules.**

**Per-site by construction (validated):**
- **Settings:** `system_settings` table is per-DB. Cache key `system::setting.<code>` is per-server file/Redis cache. Confirmed at line 217 of `modules/system/models/SettingModel.php`.
- **Invoices/InvoiceLines:** `logingrupa_goods_received_*` tables are per-DB.
- **Offer/Product writes:** per-DB.
- **OctoberCMS scheduler:** runs per-server cron; if we add a scheduled cleanup for stale `status='parsed'` invoices, it runs once per site against its own DB. Safe.

**Risks to flag (none of these are blockers, all are PITFALLS):**
- **Filename leakage:** if an operator uploads a `_lv_` invoice file to the .no backend, our parser does not enforce that the filename's country code matches the deployment. **Mitigation:** add a guard in `InvoiceNumberResolver` that compares the parsed country segment to `App::environment()` or to a Settings value, configurable per site.
- **Composer cache:** the plugin installs from a single GitHub repo. All three sites get the same code. No leak, but means a per-site feature flag must live in `Settings`, not `composer.json`.
- **Cache backend:** if any site is configured with `CACHE_DRIVER=database` (none currently are — `.env.example` defaults to file), `system_settings` cache writes hit the same DB the settings live in. Still per-site safe but worth noting in PITFALLS for ops.
- **Storage path:** uploaded HTM files go to `storage/app/uploads/invoices/` per server (Forge servers do not share storage). Safe by default; do not add S3 unless operator team confirms — see PROJECT.md "no shared state assumed".

**No singleton pattern leaks:** static `::instance()` accessors hold per-PHP-process state. Each site is a separate FPM pool. No cross-site bleed.

**No queue leaks:** `SettingModel::settingAfterSave` calls `Artisan::call('queue:restart')` on every save (line 87 of `modules/system/models/SettingModel.php`). Per-server queue worker only — the restart does not propagate cross-site. Acceptable side effect.

## Build order (dependencies first)

| Order | Component | Why first |
|---|---|---|
| 1 | `models/Settings.php` + `models/settings/fields.yaml` + Migration `create_table_invoices.php` + `create_table_invoice_lines.php` | Everything else needs the schema. |
| 2 | `classes/dto/*` (ParsedInvoice, ParsedLine, MatchResult, ApplyResult) | Contracts crossed by every layer. PHPStan-typed boundaries. |
| 3 | `classes/exception/*` | Thrown by services + caught by orchestrator + tested in unit. |
| 4 | `classes/support/SettingsAccessor.php` + Unit test | Used by ActiveFlagService and InitialResetService. |
| 5 | `classes/parser/HtmInvoiceParser.php` + `InvoiceNumberResolver.php` + Unit tests (with fixtures) | Pure, no upstream dependencies. Tested first. |
| 6 | `classes/match/EanMatcherService.php` + Feature test | Needs Offer/Product seeders only. |
| 7 | `models/Invoice.php` + `models/InvoiceLine.php` + relations | Persistence layer for orchestrator. |
| 8 | `classes/apply/StockApplyService.php` + Feature test | Now we have everything to write stock. |
| 9 | `classes/apply/ActiveFlagService.php` + Feature test (matrix) | Reconciler — needs SettingsAccessor + Offer model. |
| 10 | `classes/apply/InitialResetService.php` + Feature test | Composes ActiveFlagService + Settings flag flip. |
| 11 | `classes/orchestrator/ParseAndPersistOrchestrator.php` + Feature test | Composes parser + matcher + persistence. |
| 12 | `classes/orchestrator/ApplyOrchestrator.php` + Feature test (incl. concurrency lock) | Composes StockApply + ActiveFlag + status flip. |
| 13 | `console/RecomputeActiveFromStock.php` + Feature test | Thin shell over ActiveFlagService — last because tested in isolation. |
| 14 | `controllers/Invoices.php` + YAML + .htm partials | UI layer. Last because depends on everything below. |
| 15 | `Plugin.php` (`registerNavigation` for Settings, `registerSettings`, `registerConsoleCommand`) | Wires it all together. |

## Anti-Patterns

### Anti-Pattern 1: Calling Services Directly from the Controller

**What people do:** `Invoices::onApply` calls `StockApplyService::instance()->apply(); ActiveFlagService::instance()->reconcile();` directly.

**Why it's wrong:** Transaction boundary leaks into the controller. Two calls = two transactions = partial-failure window. Violates the "transactions in one place" rule.

**Do this instead:** Controller calls `ApplyOrchestrator::instance()->apply($iId, $iUser)`. Orchestrator owns the `DB::transaction(...)`.

### Anti-Pattern 2: Bypassing Eloquent Model Events on Bulk Updates

**What people do:** `DB::statement('UPDATE lovata_shopaholic_offers SET quantity = 0, active = 0')` for the initial reset because "it's faster".

**Why it's wrong:** `OfferModelHandler` (`plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php`) is the **only** thing that invalidates `OfferListStore::instance()->active` and `ProductItem::clearCache($id)`. Bypassing means a stale cache for up to 24h. Frontend keeps showing now-deactivated offers as available.

**Do this instead:** chunked `Offer::chunk(500, fn ($obOffers) => $obOffers->each->save())` — handlers fire, caches invalidate, ~100s for 10k rows is acceptable for a one-shot reset.

### Anti-Pattern 3: Storing Parsed-But-Not-Applied State in Session

**What people do:** Parse the upload, stash the DTO in `Session::put('parsed_invoice_42', $dto)`, render the preview from session.

**Why it's wrong:** Operator refresh = lost. Operator switches devices = lost. Concurrent uploads collide. No audit trail of attempted-but-abandoned parses. October's `RelationController` cannot consume session data — preview UI requires custom rendering.

**Do this instead:** persist `Invoice` with `status='parsed'` immediately. Preview is a `RelationController`-rendered view. Cron sweeps stale parsed rows nightly.

### Anti-Pattern 4: Scattering `Settings::get()` Across the Codebase

**What people do:** every service calls `Settings::get('auto_deactivate_on_zero')` directly when needed.

**Why it's wrong:** Violates DRY. Renames break grep-ability. Memoization is per-call-site (or absent). PROJECT.md explicitly bans it.

**Do this instead:** all reads go through `SettingsAccessor::instance()->autoDeactivateOnZero(): bool`. CI grep enforces.

### Anti-Pattern 5: Subclassing Upstream Lovata Models

**What people do:** `class GoodsReceivedOffer extends \Lovata\Shopaholic\Models\Offer { ... }` to add behavior.

**Why it's wrong:** Lovata stores reference `Offer::class` directly. Subclass instances are never built by `OfferItem::make()` — your overridden methods never fire on the read path. Breaks cache. Breaks Items. CLAUDE.md explicitly forbids it.

**Do this instead:** `Offer::extend(function ($obModel) { $obModel->addDynamicMethod(...); })` in `Plugin::boot()`. Or — for our case — keep stock writes in our own service that calls the unmodified `Offer` model.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| n/a | — | MVP is backend-only file upload. No outbound HTTP. |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| `Controllers\Invoices` ↔ Orchestrators | direct method call (singleton) | controller stays thin per Lovata convention |
| Orchestrators ↔ Services | direct method call (singleton) | DTOs cross the wire |
| `StockApplyService` ↔ `Lovata\Shopaholic\Models\Offer` | Eloquent `Offer::find()` + `->save()` | triggers `OfferModelHandler` events — keep cache consistent |
| `ActiveFlagService` ↔ `Lovata\Shopaholic\Models\Product` | Eloquent `Product::find()` + `->save()` | triggers `ProductModelHandler` events |
| `SettingsAccessor` ↔ `Models\Settings` | `Settings::get($sKey, $default)` | single accessor — DRY enforced by CI grep |
| Plugin ↔ October Backend | YAML config (`controllers/invoices/config_*.yaml`) + behaviors | mirror `plugins/logingrupa/storeextender/controllers/groups/` |
| Plugin ↔ October Settings menu | `Plugin::registerSettings()` | NOT `registerNavigation()` — settings menu only per PROJECT.md D6 |
| Tests ↔ Plugin singletons | `tearDown()` calls `Service::flush()` for every singleton | extend `GoodsReceivedTestCase::tearDown()` to flush all singletons |

### Upstream Touch Points (read-only or write-with-events)

| Upstream | Operation | Plugin file that touches it |
|---|---|---|
| `Lovata\Shopaholic\Models\Offer` | `find()`, `save()` (quantity++, active toggle), `whereIn('code', ...)` | `EanMatcherService`, `StockApplyService`, `ActiveFlagService`, `InitialResetService` |
| `Lovata\Shopaholic\Models\Product` | `find()`, `save()` (active toggle), `whereIn('code', ...)`, `withCount('offer')` | `EanMatcherService`, `ActiveFlagService`, `InitialResetService` |
| `Lovata\Shopaholic\Classes\Event\Offer\OfferModelHandler` | event subscriber (already wired by upstream Plugin) | implicit — fires on every `Offer::save()` |
| `System\Models\SettingModel` | inheritance | `Models\Settings` |
| `Lovata\Toolbox\Classes\Event\ModelHandler` | NOT used directly — we do not extend upstream models | — |

## Sources

- `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/PROJECT.md` (proposed architecture)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md` (plugin conventions)
- `/home/forge/nailscosmetics.lv/CLAUDE.md` (project-wide architecture rules)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/models/Offer.php` (`code` field, `quantity` cast, `setQuantityAttribute`)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/models/Product.php` (`code` field)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/updates/create_table_offers.php` (offer.code index — line 41)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/updates/create_table_products.php` (product.code index — line 39)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php` (cache invalidation cascade on Offer save)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/event/ModelHandler.php` (base ModelHandler subscribe pattern)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/models/CommonSettings.php` (alternative settings base — rejected with rationale)
- `/home/forge/nailscosmetics.lv/modules/system/models/SettingModel.php` (settings cache key, queue restart, getSettingsRecord)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/postnordshippingshopaholic/Plugin.php` (boot subscribe pattern — reference)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/postnordshippingshopaholic/models/Settings.php` (Settings model reference)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/postnordshippingshopaholic/classes/api/PostNordShippingProcessor.php` (strict_types service pattern)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/Plugin.php` (Event::subscribe + extend patterns)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/controllers/Groups.php` (backend ListController/FormController/RelationController reference)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/storeextender/controllers/groups/config_form.yaml` (YAML form config reference)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/extendshopaholic/classes/services/ImportLoggerService.php` (logging service — soft-dep candidate)
- `/home/forge/nailscosmetics.lv/plugins/logingrupa/extendshopaholic/Plugin.php` (event subscribe + console registration)
- `/home/forge/nailscosmetics.lv/plugins/lovata/shopaholic/classes/store/brand/ListByCategoryStore.php` (`::instance()` singleton pattern reference — line 53)
- `/home/forge/nailscosmetics.lv/plugins/lovata/toolbox/classes/helper/PriceHelper.php` (`self::instance()` pattern — line 33)

---
*Architecture research for: GoodsReceivedShopaholic plugin (subsequent milestone — GRN import)*
*Researched: 2026-04-29*
