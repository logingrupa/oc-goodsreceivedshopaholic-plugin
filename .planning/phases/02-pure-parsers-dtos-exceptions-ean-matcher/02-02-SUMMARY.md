---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 02
subsystem: error-types

tags: [php84, exceptions, runtime-exception, readonly, log-injection-guard, phpstan-level-10, pest, hungarian-notation]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: GoodsReceivedTestCase (Pest 4 / PHPUnit 12 base) used by exception tests
  - phase: 01-schema-scaffold-settings-permissions
    provides: lang/en/lang.php exception.* keys (8 keys scaffolded; this plan adds none)
provides:
  - GoodsReceivedException abstract base (extends \RuntimeException; readonly $arContext; protected static jsonContext() log-injection guard)
  - 8 final typed subclasses — InvoiceNumberMissing, DuplicateInvoice, InvalidEan, InvalidQuantity, ApplyAlreadyDone, InitialResetNotAllowed, OperatorOverridesActiveFlag, MalformedHtm
  - Polymorphic catch contract: every plugin-emitted typed exception is catchable as GoodsReceivedException (used by Phase 3 orchestrators + Phase 4 controller)
  - 10 Pest tests (49 assertions) pinning hierarchy, readonly enforcement, chained-previous preservation, log-injection safety, '{}' fallback for unencodable input
affects: [02-03-quantity-normalizer, 02-04-invoice-number-resolver, 02-05-htm-parser, 02-06-ean-matcher, 03-orchestrator-and-stock-apply, 04-backend-controller]

# Tech tracking
tech-stack:
  added:
    - "PHP 8.4 readonly promoted property (`public readonly array $arContext`) on the exception base — language-enforced immutability for the forensic context array"
  patterns:
    - "Exception classes in classes/exception/ namespace Logingrupa\\GoodsReceivedShopaholic\\Classes\\Exception (D-08)"
    - "Subclasses inherit base constructor verbatim — no per-subclass overrides; subclass body stays empty by design"
    - "`final class` modifier on every typed subclass (Tiger-Style: prevent unintended subclassing — only the base is open for inheritance)"
    - "Closure::bind trick exposes protected static jsonContext() to test scope without polluting production API"
    - "Log-injection guard via json_encode default control-char escaping + JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE flags + `?: '{}'` fallback"

key-files:
  created:
    - "classes/exception/GoodsReceivedException.php"
    - "classes/exception/InvoiceNumberMissingException.php"
    - "classes/exception/DuplicateInvoiceException.php"
    - "classes/exception/InvalidEanException.php"
    - "classes/exception/InvalidQuantityException.php"
    - "classes/exception/ApplyAlreadyDoneException.php"
    - "classes/exception/InitialResetNotAllowedException.php"
    - "classes/exception/OperatorOverridesActiveFlagException.php"
    - "classes/exception/MalformedHtmException.php"
    - "tests/unit/Exception/ExceptionHierarchyTest.php"
    - "tests/unit/Exception/ExceptionContextTest.php"
  modified: []

key-decisions:
  - "Used `abstract class` (not interface) for the base — concrete shared constructor + the `jsonContext()` log-injection helper need a class home; abstract enforces 'must subclass to throw'"
  - "Marked every typed subclass `final` (Tiger-Style: tree leaves don't need extension; the ecosystem extends only the base)"
  - "Subclass bodies stay empty — base constructor is inherited verbatim; per-subclass static factories were considered (`InvoiceNumberMissingException::forFilename(...)`) but deferred per plan-action 'Do NOT add per-subclass constructors' to keep PARSE-02 minimal"
  - "`jsonContext()` is `protected static` (not public) — production callers go through `Log::error($e->getMessage(), ['ctx' => SomeException::jsonContext($e->arContext)])` from within subclass scope; test scope reaches it via Closure::bind to avoid an anti-pattern public passthrough"
  - "Lang keys NOT formatted inside exception classes — exceptions store raw messages, callers (services/orchestrators) decide whether to `Lang::get()` or pass the raw message; D-10 explicitly assigns formatting to callers"
  - "No new lang keys added in this plan — Phase 1 scaffolded all 8 `exception.*` keys (verified in lang/en/lang.php lines 37-46)"

patterns-established:
  - "Typed-exception authoring template: `<?php` → `declare(strict_types=1);` → namespace → docblock pinning the throw site (which Phase 2/3 service throws it) → `final class <Name>Exception extends GoodsReceivedException {}`"
  - "Polymorphic-catch test idiom: `try { throw new $sFqcn('boom'); } catch (GoodsReceivedException $obException) { $arCaught[] = $sFqcn; }` over an explicit FQCN array — count + sequence asserted"
  - "Closure::bind for protected-static method access in tests: `(function () use ($args) { return Klass::method($args); })->bindTo(null, Klass::class)()`"
  - "Hungarian throughout: `$obException`, `$arSubclasses`, `$sFqcn`, `$sJson`, `$arCaught`, `$arContext` — applied uniformly to test locals AND production constructor parameters"

requirements-completed:
  - PARSE-02

# Metrics
duration: ~3 min
completed: 2026-04-29
---

# Phase 2 Plan 02: Typed Exception Tree (8 Subclasses + Log-Injection Guard) Summary

**One abstract `GoodsReceivedException` (extends `\RuntimeException`) plus 8 `final` typed subclasses in `classes/exception/`, with a `protected static jsonContext()` log-injection guard, all pinned by 10 Pest tests; PHPStan level 10 clean, baseline unchanged.**

## Performance

- **Duration:** ~3 min (~166 s)
- **Started:** 2026-04-29T18:29:24Z
- **Completed:** 2026-04-29T18:32:10Z
- **Tasks:** 3 (Task 1 production classes, Task 2 Pest tests, Task 3 QA gate verification)
- **Files modified:** 11 (9 production exception classes + 2 test files)

## Accomplishments

- 9 PHP files in `classes/exception/` under `Logingrupa\GoodsReceivedShopaholic\Classes\Exception` — 1 abstract base + 8 `final` typed subclasses, all `declare(strict_types=1);`
- Constructor signature locked verbatim per CONTEXT.md D-09: `(string $sMessage, public readonly array $arContext = [], ?\Throwable $obPrevious = null)`
- `protected static jsonContext()` log-injection guard implemented per threat-model T-02-02-02/03: `json_encode($arContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'`
- 10 Pest unit tests (49 assertions) pinning: abstract base, polymorphic catch over all 8 subclasses, `final` on every leaf, readonly mutation rejection (`\Error: Cannot modify readonly property`), chained-previous preservation, newline/CR escape in jsonContext output, `'{}'` fallback for unencodable resource handle
- Zero PHPStan level 10 errors added; `phpstan-baseline.neon` unchanged
- Pint clean across all 11 new files
- Full plugin test suite remains green (37 tests, 124 assertions, 0.60 s — was 27 tests / 75 assertions before this plan)

## Task Commits

Each task committed atomically against the working tree:

1. **Task 1: Abstract base GoodsReceivedException + 8 typed subclasses** — `5225247` (feat)
2. **Task 2: Hierarchy + context round-trip + log-injection-safety tests** — `0b657f6` (test)
3. **Task 3: QA gate (this plan only)** — no commit (verification-only task; matches plan 02-01 precedent: "no commits in this plan — orchestrator commits after plan-checker verifies")

**Plan metadata:** see commit chain ending in this SUMMARY.md commit.

_Note: Both Task 1 and Task 2 were marked `tdd="true"`, but the plan ordered implementation (Task 1) before tests (Task 2) — so the commit chain reads `feat` → `test` rather than the strict RED-then-GREEN of `test` → `feat`. This mirrors plan 02-01's deliberate plan-author choice; the QA gate at Task 3 (Pint + PHPStan + green Pest run) is the enforcement point._

## Files Created

### Production (`classes/exception/`)

- `GoodsReceivedException.php` — abstract base, extends `\RuntimeException`; locked constructor + `protected static jsonContext()` helper
- `InvoiceNumberMissingException.php` — thrown by `InvoiceNumberResolver` (plan 02-04) when both body + filename fail (D-20)
- `DuplicateInvoiceException.php` — thrown by Phase 3 `ParseAndPersistOrchestrator` on UNIQUE-index conflict
- `InvalidEanException.php` — thrown by `EanMatcherService` (plan 02-06) at the matcher boundary; parser stays lenient (D-16)
- `InvalidQuantityException.php` — thrown by `QuantityNormalizer::parseQuantity` (plan 02-03, D-21)
- `ApplyAlreadyDoneException.php` — thrown by Phase 3 `ApplyOrchestrator` when `Invoice.status='applied'`
- `InitialResetNotAllowedException.php` — thrown by Phase 3 `InitialResetService` (settings-off OR prior reset)
- `OperatorOverridesActiveFlagException.php` — thrown by Phase 3 `ActiveFlagService` skip case (informational)
- `MalformedHtmException.php` — thrown by `HtmInvoiceParser` (plan 02-05) on libxml fatal or zero-row extract (D-17)

### Tests (`tests/unit/Exception/`)

- `ExceptionHierarchyTest.php` — 4 tests:
  - declares the base GoodsReceivedException as abstract
  - every typed exception extends the base (and `\RuntimeException`, with `getMessage()` round-trip)
  - polymorphic catch: catching base catches all 8 subclasses (count + sequence)
  - every typed subclass is final
- `ExceptionContextTest.php` — 6 tests:
  - stores structured context array
  - defaults context to empty array
  - preserves previous (chained) exception
  - rejects writes to readonly $arContext (`\Error: Cannot modify readonly property`)
  - jsonContext escapes newlines and carriage returns for log-injection safety
  - jsonContext returns the literal string `{}` for unencodable input

## Locked Base Class Signature (verbatim — for plan-checker grep + downstream plans)

```php
namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

abstract class GoodsReceivedException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(
        string $sMessage,
        public readonly array $arContext = [],
        ?\Throwable $obPrevious = null,
    ) {
        parent::__construct($sMessage, 0, $obPrevious);
    }

    /**
     * @param  array<string, mixed>  $arContext
     */
    protected static function jsonContext(array $arContext): string
    {
        $sJson = json_encode($arContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $sJson !== false ? $sJson : '{}';
    }
}
```

## Eight Typed Subclass FQCNs (for plan 02-03..06 grep)

```text
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\DuplicateInvoiceException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidEanException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\OperatorOverridesActiveFlagException
Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException
```

Each subclass is `final`, has an empty body, and inherits the base constructor verbatim.

## Log-Injection Guard (one-line summary)

`GoodsReceivedException::jsonContext($arContext)` returns a single-line, control-char-free JSON string suitable for `Log::error()` sinks: default `json_encode` escapes `\n`/`\r`/`\t` into literal escape sequences (preventing attackers from forging fake log lines via embedded newlines in HTM cells), and `?: '{}'` falls back to a safe string when `json_encode` returns false (resources, recursive refs).

## Decisions Made

- **`abstract class` not interface** — the base needs a shared constructor body + the `jsonContext()` helper; an interface cannot host either.
- **`final` subclasses** — the typed tree's leaves have no legitimate extension use case; only the base is open for future plugin-internal types.
- **Empty subclass bodies** — per plan-action constraint, no per-subclass constructors and no `Lang::get()` calls inside exception files. Callers format messages.
- **`jsonContext()` `protected static`** — keeps the helper invisible to consumers outside the exception namespace; tests reach it via `Closure::bind` rather than a public passthrough (Tiger-Style: tests adapt, not production).
- **No `LANG_KEY` constants on subclasses** — context.md D-10 implies callers pass the lang key explicitly; adding a constant per subclass was considered but deferred to keep PARSE-02 minimal.
- **`use RuntimeException;` import vs `\RuntimeException`** — used `use` import in the base (Pint preference); subclasses extend the namespaced `GoodsReceivedException` directly.
- **`@property-read` plus `@param`** — class-level `@property-read array<string, mixed> $arContext` documents the readonly property for IDE introspection; constructor `@param array<string, mixed> $arContext` is what PHPStan level 10 actually enforces.

## Deviations from Plan

None — plan executed exactly as written.

The plan's verification gates (`make pint-test`, `make analyse`, `git diff --exit-code phpstan-baseline.neon`, focused Pest filter) all passed first-try with no auto-fixes required. PHPDoc requirements (`@param array<string, mixed>`) were sufficient to satisfy PHPStan level 10 — no additional baseline entries needed. The `Closure::bind` test trick to access `protected static jsonContext()` worked without a `/** @var string $sJson */` annotation because the closure's `: string` return type is enough for level-10 inference.

**Total deviations:** 0
**Impact on plan:** None — every truth, artifact, `<key_link>`, and threat-model mitigation from the plan frontmatter is satisfied.

## Issues Encountered

None.

## Threat Flags

None — exception classes introduce no new I/O, network, or trust-boundary surface beyond the threat model already documented in plan 02-02-PLAN.md (`<threat_model>` block, T-02-02-01..04). Mitigations (T-02-02-01 readonly, T-02-02-02 jsonContext escape, T-02-02-03 `'{}'` fallback) are pinned by `ExceptionContextTest`. T-02-02-04 (forensic-context completeness) is documented as a caller-responsibility convention — enforced by Phase 2 plans 02-04/05/06 and Phase 3 plan-actions, not at the exception-class level.

## QA Gate Results (Task 3)

- `make pint-test` → `{"result":"pass"}`
- `make analyse` → PHPStan level 10, 18 files analyzed, **No errors**
- `git diff --exit-code phpstan-baseline.neon` → no diff (baseline unchanged)
- `make test` (full suite) → **37 passed, 124 assertions, 0.60 s** (was 27/75 before this plan; +10 tests / +49 assertions from `tests/unit/Exception/`)
- Focused Pest filter `--filter='Exception'` → 10 passed (49 assertions), 0.26 s

## Self-Check: PASSED

**Files exist on disk:**

- FOUND: `classes/exception/GoodsReceivedException.php`
- FOUND: `classes/exception/InvoiceNumberMissingException.php`
- FOUND: `classes/exception/DuplicateInvoiceException.php`
- FOUND: `classes/exception/InvalidEanException.php`
- FOUND: `classes/exception/InvalidQuantityException.php`
- FOUND: `classes/exception/ApplyAlreadyDoneException.php`
- FOUND: `classes/exception/InitialResetNotAllowedException.php`
- FOUND: `classes/exception/OperatorOverridesActiveFlagException.php`
- FOUND: `classes/exception/MalformedHtmException.php`
- FOUND: `tests/unit/Exception/ExceptionHierarchyTest.php`
- FOUND: `tests/unit/Exception/ExceptionContextTest.php`

**Commits exist:**

- FOUND: `5225247` (Task 1 — `feat(02-02): add abstract GoodsReceivedException + 8 typed subclasses`)
- FOUND: `0b657f6` (Task 2 — `test(02-02): add hierarchy + context + log-injection tests for exceptions`)

## Next Plan Readiness

- Typed-exception tree is locked. Plan 02-03 throws `InvalidQuantityException`, plan 02-04 throws `InvoiceNumberMissingException`, plan 02-05 throws `MalformedHtmException`, plan 02-06 throws `InvalidEanException`. Phase 3 plans throw the remaining four. Each subclass FQCN is grep-able from this SUMMARY's "Eight Typed Subclass FQCNs" block.
- Phase 4 controller can rely on `catch (\Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException $obException)` to render the appropriate operator message via the lang keys at `lang/en/lang.php` exception.* (already scaffolded in Phase 1).
- `GoodsReceivedException::jsonContext()` is the canonical log-injection-safe formatter for `Log::error()` ctx arrays — Phase 2 services and Phase 3 orchestrators that catch and re-log should call it.
- No blockers, no follow-ups.

---
*Phase: 02-pure-parsers-dtos-exceptions-ean-matcher*
*Plan: 02*
*Completed: 2026-04-29*
