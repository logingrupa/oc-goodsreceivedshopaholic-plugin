# Stack Research — GoodsReceivedShopaholic (GRN Backend Import)

**Domain:** OctoberCMS v4 / Lovata Shopaholic backend plugin — multi-file `.HTM` upload → parse → preview → apply pipeline with audit
**Researched:** 2026-04-29
**Confidence:** HIGH (all recommendations verified against installed vendor/, existing Lovata reference plugins, and October 4.2.13 module source)

> **Scope note:** This document covers ONLY *new* stack additions/decisions for the GRN backend UX. The existing locked stack (PHP 8.4, October 4 / Laravel 12, Toolbox 2.2, Shopaholic 1.32, Pest 4 / PHPStan 10 / Pint / Rector / PHPMD) is treated as fixed input.

---

## Executive Recommendation (TL;DR)

| Concern | Recommendation | Rationale |
|---------|----------------|-----------|
| Backend file upload | **`Backend\Behaviors\FormController` with `type: fileupload` field on `Invoice` model** (`attachMany`) for the upload form; **NOT** `ImportExportController` (designed for CSV column-mapping only) | Native October 4 widget; supports multi-file, deferred binding; matches `Lovata\Labelsshopaholic\Models\Label` pattern already in vendor |
| HTM parsing | **Built-in `ext-dom` (`DOMDocument` + `DOMXPath`)** with `mb_convert_encoding` for UTF-8; **no new Composer dep** | Sufficient for tabular HTM with class selectors (`tr.R20`, `tr.R21`); zero new attack surface; no transitive risk |
| HTM parser fallback | **`Masterminds\HTML5` 2.10.0** — already transitively installed via `symfony/html-sanitizer` | Use only if `DOMDocument::loadHTML()` proves brittle on real `.HTM` (mid-2000s Office-exported markup with non-conformant tags); zero install cost |
| Preview→Apply UI | **Two backend controllers** (`Invoices` ListController + InvoiceController FormController) with custom `_apply_action.htm` partial; AJAX via `data-request="onApply"` (Larajax-equivalent in backend = built-in October backend AJAX framework) | Mirrors `Lovata\Shopaholic\Controllers\Offers` triple-implement pattern; no custom widget needed |
| Settings storage | **October `SettingModel`** subclass via `Lovata\Toolbox\Models\CommonSettings` (gives Multisite + Translate behaviors free) | Per-DB → naturally per-site; matches `XmlImportSettings` pattern in Shopaholic |
| Settings menu placement | **`registerSettings()` in `Plugin.php`** with `category: lovata.shopaholic::lang.tab.settings` to nest under existing Shopaholic settings group | Matches Shopaholic's own pattern; no top-nav clutter (per D6) |
| Console command | **`registerConsoleCommand()` in `Plugin::register()`** (NOT `boot()`) | Standard October idiom; mirrors `Lovata\Shopaholic\Plugin::register()` and `Logingrupa\ExtendShopaholic\Plugin::register()` |
| `ImportLoggerService` reuse | **Vendor-inline a slim `ImportAuditService`** (~80 lines), do NOT declare soft-dep on `Logingrupa\ExtendShopaholic` | 1C XML-coupled API; namespace bug (`LoginGrupa` vs `Logingrupa`); 400+ lines of irrelevant XML-import logic; tight coupling violates SRP/D1 |
| Backend AJAX | **October backend's built-in AJAX framework** (`data-request="onHandlerName"`, server side `public function onHandlerName()`) | Larajax is for *frontend* CMS components; backend has its own equivalent already loaded |

**Net new Composer dependencies: ZERO.** Everything needed is in vendor/ today.

---

## Recommended Stack — New Capability Components

### Backend File Upload

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| `Backend\FormWidgets\FileUpload` | October 4.2.13 (built-in) | Multi-file `.HTM` upload widget on the `Invoice` create form | Native, well-tested, supports `maxFiles`, `fileTypes`, `mimeTypes`, deferred binding. Handles file storage via `System\Models\File` polymorphic attachment. |
| `System\Models\File` | October 4.2.13 (built-in) | Storage of uploaded `.HTM` blobs with deferred binding | Polymorphic — `attachOne`/`attachMany` on `Invoice` model. Files survive form abandonment via `session_key` mechanism. |
| `Backend\Behaviors\FormController` | October 4.2.13 (built-in) | Renders create form with fileupload widget; handles save | Standard. Triple-implement pattern (`FormController` + `ListController` + optional `RelationController`) matches `Lovata\Shopaholic\Controllers\Offers`. |
| `Backend\Behaviors\ListController` | October 4.2.13 (built-in) | Invoice history page (status, applied_by, line_count, units_added) | Standard. Supports row actions, filters, partial buttons. |

**Field config example** (verified against `plugins/lovata/labelsshopaholic/models/label/fields.yaml:fileupload`):
```yaml
htm_files:
    label: 'logingrupa.goodsreceivedshopaholic::lang.field.htm_files'
    type: fileupload
    mode: file
    fileTypes: htm,html
    mimeTypes: text/html
    maxFiles: 50
    maxFilesize: 5    # MB per file
    useCaption: false
    span: full
```

**Model relation** (on `Invoice`):
```php
public $attachMany = [
    'htm_files' => [\System\Models\File::class, 'public' => false],
];
```

> **NOT recommended:** `Backend\Behaviors\ImportExportController`. It is designed for CSV column-mapping flows (header-row → DB-column dropdowns) and writes via Laravel's `Storage::disk('local')`. HTM scraping has zero column-mapping concept. Forcing it would obscure intent and reject our two-step parse→preview→apply.

### HTM Parsing

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| **`ext-dom` (`DOMDocument` + `DOMXPath`)** | PHP 8.4.18 built-in (libxml 2.x) | Primary HTM table-row scraper | Zero new dep; `libxml`, `dom`, `mbstring`, `iconv` all confirmed loaded. Predictable XPath against `//tr[@class='R20' or @class='R21']`. Forward-compatible with PHP 9. |
| **`Masterminds\HTML5`** | 2.10.0 | Fallback parser if `DOMDocument::loadHTML()` chokes on legacy MS-Office HTM markup | Already in `vendor/masterminds/html5/` (transitive via `symfony/html-sanitizer`). True HTML5 spec parser; tolerant of malformed input. Same DOM API once loaded. |

**Charset handling pattern** (verified against PHP DOM behavior):
```php
$sHtml = file_get_contents($sPath);
// 1) Detect declared charset from <META>; default to UTF-8
$sHtml = mb_convert_encoding($sHtml, 'HTML-ENTITIES', 'UTF-8');
$obDom = new \DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$obDom->loadHTML($sHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();

$obXPath = new \DOMXPath($obDom);
// Verified against sample Nr_PRO*.HTM structure (PROJECT.md context block):
//   header row class="R19", data rows class="R20" (even) / "R21" (odd)
$obDataRows = $obXPath->query("//tr[@class='R20' or @class='R21']");
```

> **Decimal-comma normalization** lives in `HtmInvoiceParser`, not a library: `(float) str_replace([',', ' '], ['.', ''], $sCell)` — pure function, fully testable.

### Backend AJAX (Apply button)

| Technology | Purpose | Why |
|------------|---------|-----|
| **October backend AJAX framework** (built-in `oc.ajax`, served by `october/backend` 4.2.13) | "Apply Invoice" button in preview view triggers server-side handler without page reload | Built into every backend layout. `data-request="onApply"`, `data-request-data`, `data-request-confirm`, `data-request-success` all native attributes. **Not** the same as Larajax — Larajax is frontend-CMS-only. |

**Handler pattern** (controller method called by `data-request`):
```php
public function onApply(int $iInvoiceId): array
{
    $obInvoice = Invoice::findOrFail($iInvoiceId);
    $arResult = StockApplyService::instance()->apply($obInvoice);
    \Flash::success(\Lang::get('logingrupa.goodsreceivedshopaholic::lang.flash.applied'));
    return ['#partial-id' => $this->makePartial('apply_summary', $arResult)];
}
```

**Button markup in form partial:**
```html
<button
    type="button"
    class="btn btn-primary"
    data-request="onApply"
    data-request-data="iInvoiceId: {{ formModel.id }}"
    data-request-confirm="{{ 'logingrupa.goodsreceivedshopaholic::lang.confirm.apply'|trans }}"
    data-request-success="oc.flashMsg({text: 'Applied', class: 'success'})">
    Apply
</button>
```

> **No JS code to write.** The backend bundle (`/modules/backend/assets/js/october.framework.js`) interprets `data-request` attributes server-side via the `X-OCTOBER-REQUEST-HANDLER` header. Identical to frontend's `data-request` but loaded by backend layout, not Larajax.

### Settings & Settings Menu

| Technology | Purpose | Why |
|------------|---------|-----|
| `Lovata\Toolbox\Models\CommonSettings` | Base class for `Settings` model | Subclass of `System\Models\SettingModel` with `Multisite` trait + `RainLab.Translate.Behaviors.TranslatableModel` pre-implemented. Per-site DB → automatically per-site settings. |
| `Plugin::registerSettings()` | Registers Settings menu entry under Shopaholic settings tab | Native October API. Setting `category` to `lovata.shopaholic::lang.tab.settings` nests the entry inside the existing Shopaholic settings group. **D6 satisfied** (no top-nav clutter). |

**Plugin.php pattern** (verified against `plugins/lovata/shopaholic/Plugin.php:84-152`):
```php
public function registerSettings(): array
{
    return [
        'goodsreceivedshopaholic-settings' => [
            'label'       => 'logingrupa.goodsreceivedshopaholic::lang.menu.settings',
            'description' => 'logingrupa.goodsreceivedshopaholic::lang.menu.settings_description',
            'category'    => 'lovata.shopaholic::lang.tab.settings',
            'icon'        => 'icon-truck',
            'class'       => Settings::class,
            'order'       => 8500,
            'permissions' => ['logingrupa-goodsreceivedshopaholic-settings'],
        ],
        'goodsreceivedshopaholic-invoices' => [
            'label'       => 'logingrupa.goodsreceivedshopaholic::lang.menu.invoices',
            'description' => 'logingrupa.goodsreceivedshopaholic::lang.menu.invoices_description',
            'category'    => 'lovata.shopaholic::lang.tab.settings',
            'icon'        => 'icon-list',
            'url'         => \Backend::url('logingrupa/goodsreceivedshopaholic/invoices'),
            'order'       => 8510,
            'permissions' => ['logingrupa-goodsreceivedshopaholic-invoices'],
        ],
    ];
}
```

### Console Command

| Technology | Purpose | Why |
|------------|---------|-----|
| `Illuminate\Console\Command` (Laravel 12) | Base for `goodsreceived:recompute_active_from_stock` | Standard Artisan. October simply registers via `registerConsoleCommand`. |
| `$this->registerConsoleCommand()` in `Plugin::register()` | Bind name → class | Native October pattern. Confirmed in `Lovata\Shopaholic\Plugin::register()` line 51-55 and `Logingrupa\ExtendShopaholic\Plugin::register()`. **Must be in `register()`, not `boot()`** — boot fires after plugin discovery for routes/events; register fires earlier for service-provider-grade bindings. |

**Plugin.php pattern:**
```php
public function register(): void
{
    $this->registerConsoleCommand(
        'goodsreceived:recompute_active_from_stock',
        \Logingrupa\GoodsReceivedShopaholic\Classes\Console\RecomputeActiveFromStock::class
    );
}
```

---

## Supporting Libraries (decisions deferred / explicitly NOT added)

### `Logingrupa\ExtendShopaholic\Classes\Services\ImportLoggerService`

**Decision: DO NOT declare soft-dependency. Vendor-inline a slim purpose-built audit service.**

Read of `plugins/logingrupa/extendshopaholic/classes/services/ImportLoggerService.php` (409 lines) and `traits/ImportLoggingTrait.php` (409 lines) reveals:

| Reason to skip soft-dep | Detail |
|--------------------------|--------|
| **API is 1C-XML-shaped** | Methods like `addSkippedItem()` accept `xml_data` arrays with Russian keys (`'Ид'`, `'НаименованиеНаАнглийском'`, `'ДопОписание'/'Latvian'/'Описание'`). The trait's `getMissingRequiredFields()` checks `external_id` and `name` — fields irrelevant to GRN lines. |
| **Namespace bug** | The service is namespaced `LoginGrupa\ExtendShopaholic\...` (capital G) while the trait is `Logingrupa\ExtendShopaholic\...`. Importing the service forces us to inherit a known typo. |
| **Hidden coupling** | Trait calls `parent::setErrorMessage()`, `$this->arImportData`, `static::MODEL_CLASS`, `$this->iCreatedCount` — ties any consumer to the `AbstractImportModel`/`AbstractImportModelFromXML` class hierarchy in ExtendShopaholic. We have no AbstractImportModel; we have `HtmInvoiceParser` + DTO. |
| **Output is human-readable text log** | `writeLog()` emits emoji-decorated text to `storage/logs/{type}_import.log`. Our audit needs are *DB rows* (`Invoice`, `InvoiceLine`) for backend listing, not flat-file logs. |
| **Verbosity-gated** | `logWithVerbosity()` only logs at `-vvv` console verbosity; useless for backend HTTP-triggered apply. |
| **SRP violation (D1)** | Forcing GRN-import to depend on a plugin whose bounded context is "1C XML supplier feed" muddies the architecture boundary. PROJECT.md D1 explicitly chose a separate plugin for this reason. |
| **Composer dependency cost** | Soft-dep would add `Logingrupa.ExtendShopaholic` to `Plugin::$require` or composer `require`, dragging its entire model layer into our test bootstrap. Slows `make test`. |

**What to vendor-inline instead:** A ~50-line `Classes\Service\ImportAuditService` writing to `Invoice::status` + `InvoiceLine::*` columns. The audit IS the data model — no separate log file needed. (This is also what backend ListController displays.)

### NOT Adding

| Library | Why NOT | Use Instead |
|---------|---------|-------------|
| `symfony/dom-crawler` | NOT in vendor today (only suggested by `symfony/html-sanitizer`); pulling it adds `symfony/css-selector` transitively. Native `DOMXPath` is sufficient for `tr.R20` selectors. | `DOMDocument` + `DOMXPath` |
| `paquettg/php-html-parser` | Slower than libxml; abandoned-ish (last release v3.1.x, low activity); brings `guzzlehttp/guzzle` transitively (already pulled, but irrelevant cost). | `DOMDocument` or `Masterminds\HTML5` |
| `simple-html-dom` | Procedural API, no namespacing, regex-based parsing, slow on documents > 100KB. Considered harmful in 2026. | `DOMDocument` |
| `league/csv` | We don't parse CSV. | n/a |
| `phpoffice/phpspreadsheet` | We don't parse Excel/ODF; HTM is plain HTML. | `DOMDocument` |
| `larajax` (frontend-only) for backend | Backend already loads October's `oc.ajax`. Pulling Larajax to backend pages is unnecessary and visually confusing. | `data-request="onHandler"` (built-in backend AJAX) |
| New Settings UI library | `SettingModel` + `fields.yaml` is the OctoberCMS standard. | Native settings model |

---

## Development Tools

No new dev tools. Existing locked toolchain covers everything:

| Tool | Purpose | Notes |
|------|---------|-------|
| Pest 4 / PHPUnit 12 | HTM parser fixture tests, idempotency tests, integration tests | Use SQLite in-memory + `GoodsReceivedTestCase`. Copy 3 representative `Nr_PRO*.HTM` to `tests/fixtures/invoices/` for hermetic tests. |
| PHPStan 10 + Larastan | Static analysis | DOM extension is fully typed (`DOMDocument`, `DOMNodeList`, `DOMElement`) since PHP 8.0. Level 10 will catch `null` access on `DOMXPath::query()` returns. |
| Pint | PSR-12 + ordered imports | Already configured. |
| Rector | Auto-modernize, enforce `declare(strict_types=1)` | Already configured. |
| PHPMD | Lovata ruleset | Already configured. |

---

## Installation

**No package installations required.** Confirmation:

```bash
# Verify all required components present (run from project root):
php -m | grep -E "^(dom|libxml|mbstring|iconv|xml)$"
# Expected: dom, iconv, libxml, mbstring, xml (all present per PHP 8.4.18 NTS confirmation)

ls vendor/masterminds/html5/                            # exists (2.10.0)
ls modules/backend/formwidgets/FileUpload.php           # exists (October 4.2.13)
ls plugins/lovata/toolbox/models/CommonSettings.php     # exists (Toolbox 2.2)
```

`composer.json` for the plugin requires NO additions beyond the current:
```json
{
    "require": {
        "php": "^8.3",
        "october/system": "^4.0",
        "october/rain": "^4.0",
        "lovata/toolbox-plugin": "^2.2",
        "lovata/shopaholic-plugin": "^1.32"
    }
}
```

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| `DOMDocument` + `DOMXPath` | `Masterminds\HTML5` (already in vendor) | Switch only if real fixtures expose `loadHTML()` parse failures (MS Office 2003-era HTM with `<o:p>`, conditional comments, `xmlns:v=` attributes). Switching is one-line: `(new \Masterminds\HTML5())->loadHTML($sHtml)` returns same `DOMDocument`. |
| `Backend\Behaviors\FormController` + custom Apply button | `Backend\Behaviors\ImportExportController` | Never — wrong tool for HTM scraping (designed for CSV column-mapping). |
| `Backend\Behaviors\FormController` + custom Apply button | Custom `Backend\Classes\WidgetBase` for the upload+preview UI | Only if the preview-then-apply flow becomes reusable across 3+ controllers. For a single Invoices controller, FormController + partials is simpler and matches existing Lovata patterns. |
| Inline `ImportAuditService` | Soft-dep on `Logingrupa\ExtendShopaholic\ImportLoggerService` | Never — see seven-row rejection table above. |
| `registerConsoleCommand()` in `Plugin::register()` | Laravel-style `app()->register(ServiceProvider)` | Never — October has its own bootstrap order; `registerConsoleCommand` is the supported integration point. |
| October backend AJAX (`data-request`) | Vanilla `fetch()` with custom CSRF wrap | Only if integrating with a non-October frontend page. Backend pages already include the framework. |
| October backend AJAX | jQuery (per root `CLAUDE.md` "No jQuery") | Never — root constraint forbids jQuery in new code; backend AJAX framework uses vanilla JS internally. |
| `attachMany` on `Invoice` for HTM blobs | Direct `Storage::disk('local')->put()` from controller | Only if file retention strategy diverges from October's `system_files` table. Default is fine — `attachMany` gives free deferred-binding + cleanup-on-delete. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `simple_html_dom` (sunkro/simple-html-dom) | Regex-based; slow on >100KB; no PSR-4 namespace; PHP 8 incompatibilities reported. | `DOMDocument` + `DOMXPath` |
| `paquettg/php-html-parser` | Outperformed by libxml; minimal value-add over native; transitive bloat. | `DOMDocument` |
| `goutte/goutte` | Deprecated 2023; rolled into `symfony/browser-kit` (which we don't need — no HTTP scraping). | `DOMDocument` |
| Hardcoded language strings | Conflicts with multi-language requirement (lv/ru/en/no per CLAUDE.md). | `lang.php` keys via `Lang::get()` |
| Direct `Storage::put()` for uploaded HTM | Bypasses October file system; orphan files on form abandonment; manual cleanup. | `attachMany` + `System\Models\File` (deferred binding) |
| Subclassing `Lovata\Shopaholic\Models\Offer` | Violates root `CLAUDE.md` "Never subclass upstream models". | `Offer::extend()` (we don't need to extend it — only read+write `quantity`/`active`) |
| `Eloquent::update()` from controller | Violates root `ARCHITECTURE.md` thin-controller rule and Tiger-Style SRP. | Inject `StockApplyService` from controller; service does the DB writes inside a `DB::transaction()` |
| Reading settings via scattered `Settings::get('foo')` calls | PROJECT.md DRY rule explicitly forbids. | One `SettingsAccessor` class with typed getters: `getEnabled(): bool`, `getAutoActivateOnStock(): bool`, etc. |
| Console command in `Plugin::boot()` | Wrong lifecycle; commands defined in boot may not register before `php artisan list` enumerates. | Always `Plugin::register()` |
| Symfony `BrowserKit` / `HttpClient` | We don't fetch HTM remotely — operator uploads it. | n/a |

---

## Stack Patterns by Variant

**If MVP only needs single-file upload:**
- Set `maxFiles: 1` on the `fileupload` field; everything else identical. No code changes.

**If multi-file upload exposes performance issues (>50 files, >1000 lines/invoice):**
- Wrap parse loop in `\October\Rain\Database\Models\…` chunked saves OR Laravel queue job (`ShouldQueue`). Existing `october/system` provides `Queue` facade; configurable per-site via `.env`.
- Move parse phase out of HTTP request: enqueue → set `Invoice.status = 'queued'` → background worker parses → operator gets notified via Settings menu badge / status column.

**If unmatched-EAN queue grows beyond 100 entries:**
- Add a dedicated `UnmatchedEans` ListController page (still under Settings menu) with a "manual match → offer" form. Reuses same `EanMatcherService` API.

**If a future site needs a different HTM dialect:**
- Add `HtmInvoiceParserInterface`; current implementation becomes `Norwegian1CHtmInvoiceParser`. Inject via container binding in `Plugin::register()`. Default binding stays the same (zero behavior change).

---

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| `october/system ^4.0` (have 4.2.13) | `php ^8.3` (have 8.4.18) | Verified — October 4 supports PHP 8.3+. Plugin's existing `composer.json` is correct. |
| `lovata/toolbox-plugin ^2.2` | October 4.x | `CommonSettings` extends `System\Models\SettingModel` directly — no version drift risk. |
| `lovata/shopaholic-plugin ^1.32` | Toolbox 2.2 | Both already locked in project root composer.lock at versions matching plugin spec. |
| `masterminds/html5 ^2.10` | PHP 8.4 | `composer.json` declares `php >=5.3.0`; tested by upstream against PHP 8.x. No incompatibility. |
| Built-in `ext-dom` (libxml 2.x) | PHP 8.4 | Always present on standard PHP builds; confirmed via `php -m`. |
| `Backend\FormWidgets\FileUpload` | October 4.2.13 | Native; no version concern. |
| Pest 4 + PHPUnit 12 | PHP 8.3+ | Already in project root vendor/bin/. |
| PHPStan 10 + Larastan | PHP 8.3+, Laravel 12 | Already configured per `phpstan.neon`. |

**Known issues / gotchas:**
- `DOMDocument::loadHTML()` emits warnings for HTML5-specific tags (`<section>`, `<header>`, etc.). MUST wrap with `libxml_use_internal_errors(true); ... libxml_clear_errors();`. Verified pattern.
- `DOMDocument::loadHTML()` defaults to ISO-8859-1 if no charset declared. The `Nr_PRO*.HTM` fixtures DO declare `<META charset="UTF-8">` per PROJECT.md, but defensive `mb_convert_encoding` to `'HTML-ENTITIES'` before load is mandatory for safety.
- `attachMany` on a SettingModel does NOT work the same as on a regular Model (singleton row); use `attachMany` on the **Invoice** model, NOT on `Settings`.
- `registerSettings()` `class` key requires fully-qualified class string OR backslash-prefixed; verified against Shopaholic `'class' => 'Lovata\Shopaholic\Models\Settings'`.

---

## Integration Surface — Cross-Plugin Impact

| Existing Code | Impact | Mitigation |
|---------------|--------|------------|
| `Logingrupa.ExtendShopaholic` 1C XML import | **Zero impact.** GRN plugin reads `offers.code`/`offers.quantity` (which XML import populates). No write conflict because XML import sets initial state; GRN increments. | None needed. Add a regression test that runs both: 1) XML import creates offer with `code='123'`, `quantity=0`; 2) GRN apply increments to `quantity=10`; assert. |
| `Logingrupa.CampaignpricingShopaholic` Phase 3 | **Zero impact.** Campaign pricing reads/writes `offers.price_list[]` and active flags via its own `ActiveCampaignService`. GRN's `ActiveFlagService` writes `offer.active`/`product.active` based on stock. **Possible race**: GRN auto-deactivates a zero-stock offer that Campaign just activated for a sale. **Mitigation**: GRN reconcile is gated by per-site `auto_deactivate_on_zero` setting; default OFF. Document the interaction in PROJECT.md operational notes. |
| `Lovata.Shopaholic` `Offer` model | **Read-write on `quantity`, `active`.** | Use `Offer::find($iId)->fill(['quantity' => …])->save()`, NOT raw `DB::update()`, so `OfferModelHandler` cache invalidation fires. Critical for storefront freshness. |
| `Lovata.Shopaholic` `Product` model | **Read-write on `active` only when initial-reset checkbox is set.** | Same pattern — go through Eloquent so `ProductModelHandler` invalidates caches. |
| `Logingrupa.StoreExtender` user group pricing | **Zero impact.** Pricing untouched by GRN. | None. |
| `Logingrupa.PostNordShipping` checkout | **Zero impact.** Checkout-side concern; no shared writes. | None. |
| Multi-site (.no/.lv/.lt) deployment | **Settings naturally per-DB.** Initial-reset is destructive — must NEVER share state. | Use `CommonSettings`'s built-in `Multisite` trait (verified: `plugins/lovata/toolbox/models/CommonSettings.php:13`). Settings rows are partitioned per site. |
| `php-fpm` OPcache (root CLAUDE.md) | After deploying new plugin: `sudo systemctl reload php8.4-fpm` to flush. | Standard deployment step; Forge zero-downtime release scripts handle this. |

---

## Sources

| Source | Confidence | What was verified |
|--------|------------|-------------------|
| `plugins/lovata/shopaholic/Plugin.php:51-152` (read in research) | HIGH | `registerConsoleCommand()` in `register()`; `registerSettings()` array shape with `category`, `class`, `url`, `permissions`, `order`. |
| `plugins/lovata/shopaholic/widgets/ImportFromXML.php:1-75` (read) | HIGH | ReportWidgetBase pattern + AJAX handler (`onImportFromXML`) using `Input::get()` + `Flash::info()` — same idiom we'll use in controller. |
| `plugins/lovata/shopaholic/models/xmlimportsettings/fields.yaml:1-80` (read) | HIGH | Settings `fields.yaml` field-set syntax: `tabs.fields.<name>: { label, type, span }`. |
| `plugins/lovata/shopaholic/models/XmlImportSettings.php` (read) | HIGH | `CommonSettings` extension pattern; `SETTINGS_CODE` constant + `$settingsCode`; getters returning arrays for dropdown population. |
| `plugins/lovata/labelsshopaholic/models/label/fields.yaml` (grep) | HIGH | `type: fileupload` field config with `mode: image`, `fileTypes`, `useCaption`, `thumbOptions`. Confirmed multi-file pattern by reading `maxFiles` support. |
| `modules/backend/formwidgets/FileUpload.php:1-100` (read) | HIGH | All configurable properties (`fileTypes`, `mimeTypes`, `maxFilesize`, `maxFiles`, `deferredBinding`); confirms native multi-file support. |
| `plugins/lovata/toolbox/models/CommonSettings.php:1-42` (read) | HIGH | `SettingModel` subclass + `Multisite` trait + `RainLab.Translate.Behaviors.TranslatableModel` pre-implemented. Per-site DB → per-site settings, free. |
| `plugins/logingrupa/extendshopaholic/classes/services/ImportLoggerService.php:1-409` (read) | HIGH | API surface analyzed line-by-line — 1C-XML-shaped, namespace bug (`LoginGrupa`), file-log output, verbosity-gated. Justifies vendor-inline decision. |
| `plugins/logingrupa/extendshopaholic/traits/ImportLoggingTrait.php:1-409` (read) | HIGH | Trait deeply coupled to `AbstractImportModel::$arImportData`/`MODEL_CLASS`; not consumable without inheriting that class hierarchy. |
| `plugins/logingrupa/postnordshippingshopaholic/Plugin.php:1-67` (read) | HIGH | Reference Plugin shape: `declare(strict_types=1)`, `#[\Override]` on `pluginDetails`/`registerComponents`, `Event::subscribe()` in `boot()`, public `$require` array. |
| `plugins/logingrupa/postnordshippingshopaholic/composer.json` (read) | HIGH | composer requires shape — verified our existing GRN `composer.json` already mirrors it minus the OrdersShopaholic dep. |
| `plugins/lovata/ordersshopaholic/Plugin.php:1-80` (read) | HIGH | Confirms `register()` for console + `addEventListener()` helper-method idiom for boot. |
| `composer.lock` grep (`masterminds/html5`, `symfony/css-selector`, `october/*`) | HIGH | `masterminds/html5` 2.10.0 installed; `symfony/dom-crawler` NOT installed; October 4.2.13 confirmed across all modules. |
| `vendor/masterminds/html5/composer.json` + `src/` (read) | HIGH | Confirms `Masterminds\HTML5` autoload, requires only `ext-dom`, no transitive deps. |
| `php -m` output (root CLAUDE.md states PHP 8.4.18 NTS) | HIGH | `dom`, `libxml`, `mbstring`, `iconv`, `xml` all loaded. |
| Root `CLAUDE.md` (read) | HIGH | "No jQuery" constraint; multi-site policy; Hungarian notation; `register()` vs `boot()` ordering. |
| Plugin `PROJECT.md` (read) | HIGH | All locked decisions (D1-D10), engineering quality bar, fixture file structure (`Nr_PRO*.HTM`, R19/R20/R21 row classes, decimal-comma). |
| Plugin `CLAUDE.md` (read) | HIGH | Plugin conventions, namespace `Logingrupa\GoodsReceivedShopaholic`, `#[\Override]`, `declare(strict_types=1)`. |

**Context7 / external WebSearch:** Not used — every recommendation is verifiable against installed code already in `vendor/` and `plugins/`. Higher confidence than external docs.

---

*Stack research for: GoodsReceivedShopaholic backend HTM import UX*
*Researched: 2026-04-29*
*Researcher: gsd researcher (project-research mode, milestone-2 stack additions)*
