<?php

declare(strict_types=1);

/**
 * Plan 05-01 — Lang completeness gate (OPS-04 / D-09 / D-18).
 *
 * Pins the load-bearing invariants for translation drift:
 *   1. Key parity: array_keys_recursive(EN) === LV === NO === RU
 *      (RainLab.Translate auto-discovers `<locale>/lang.php` files; a missing
 *      key in any locale silently falls back to the EN string. Backend ops on
 *      .no would unexpectedly see English strings — silent UX bug. Hard-fail.)
 *   2. No empty / null / non-string leaves in any locale (operator must never
 *      see a blank label or `null` rendered into a Twig backend partial).
 *   3. No English leftovers (sample-based smoke on 3 high-visibility keys per
 *      locale — catches the common copy-paste-and-forget-to-translate bug).
 *   4. Placeholder preservation: every `:\w+` token in EN values MUST appear
 *      in the same key of every other locale (e.g. `:size`, `:offer_count`).
 *      A dropped placeholder corrupts the rendered backend message.
 *   5. Typed-confirmation literals "OVERRIDE" and "RESET" preserved verbatim
 *      in `override.typed_hint` and `initial_reset.typed_hint` per D-19 / D-22
 *      — these are server-side strict-equality tokens; translating them to
 *      "PĀRRAKSTĪT" / "ATIESTATĪT" would silently break confirmation gates.
 *
 * Pure structural test — no DB, no model, no boot. Hermetic — only reads
 * lang/*.php files inside the plugin tree. Runs in Pest 4 / PHPUnit 12.
 */

/**
 * Flatten a nested associative array into dot-prefixed leaf-key paths.
 *
 * Example: ['a' => ['b' => 'x', 'c' => 'y'], 'd' => 'z']
 *   → ['a.b', 'a.c', 'd']  (sorted ascending)
 */
function arrayKeysRecursive(array $arInput, string $sPrefix = ''): array
{
    $arResult = [];
    foreach ($arInput as $sKey => $mValue) {
        $sFullKey = $sPrefix === '' ? (string) $sKey : $sPrefix.'.'.$sKey;
        if (is_array($mValue)) {
            $arResult = array_merge($arResult, arrayKeysRecursive($mValue, $sFullKey));

            continue;
        }
        $arResult[] = $sFullKey;
    }
    sort($arResult);

    return $arResult;
}

/**
 * Load a locale lang file and assert it exists. Returns the parsed array.
 *
 * Fails loudly (custom message) when the file is missing — this is the
 * difference between a structural drift (missing key) and a missing-file
 * regression (someone deleted a locale).
 */
function loadLocale(string $sLocale): array
{
    $sPath = __DIR__.'/../../../lang/'.$sLocale.'/lang.php';
    $sReal = realpath($sPath);
    expect($sReal)->not->toBeFalse(
        "Lang file not found for locale '$sLocale' at $sPath"
    );
    /** @var array<string, mixed> $arData */
    $arData = require $sReal;
    expect($arData)->toBeArray("Lang file for '$sLocale' did not return an array");

    return $arData;
}

/**
 * Walk every leaf string value in a nested lang array and assert it is a
 * non-empty string. Returns nothing — assertions fire inline.
 */
function assertAllLeavesNonEmptyString(array $arInput, string $sLocale, string $sPrefix = ''): void
{
    foreach ($arInput as $sKey => $mValue) {
        $sFullKey = $sPrefix === '' ? (string) $sKey : $sPrefix.'.'.$sKey;
        if (is_array($mValue)) {
            assertAllLeavesNonEmptyString($mValue, $sLocale, $sFullKey);

            continue;
        }
        expect($mValue)->toBeString("Locale $sLocale key $sFullKey is not a string");
        expect(strlen((string) $mValue))->toBeGreaterThan(0, "Locale $sLocale key $sFullKey is an empty string");
    }
}

/**
 * Resolve a dot-path key into a nested array. Returns null if missing.
 */
function digKey(array $arInput, string $sDotKey): mixed
{
    $arParts = explode('.', $sDotKey);
    $mCursor = $arInput;
    foreach ($arParts as $sPart) {
        if (! is_array($mCursor) || ! array_key_exists($sPart, $mCursor)) {
            return null;
        }
        $mCursor = $mCursor[$sPart];
    }

    return $mCursor;
}

/**
 * Walk EN tree and yield [dotKey, enValue] pairs whose enValue contains a
 * `:placeholder` token (Lang::get-style substitution). Used by test 6.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function collectPlaceholderKeys(array $arInput, string $sPrefix = ''): array
{
    $arResult = [];
    foreach ($arInput as $sKey => $mValue) {
        $sFullKey = $sPrefix === '' ? (string) $sKey : $sPrefix.'.'.$sKey;
        if (is_array($mValue)) {
            $arResult = array_merge($arResult, collectPlaceholderKeys($mValue, $sFullKey));

            continue;
        }
        if (is_string($mValue) && preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $mValue, $arMatches) > 0) {
            foreach ($arMatches[0] as $sToken) {
                $arResult[] = [$sFullKey, $sToken];
            }
        }
    }

    return $arResult;
}

/* ------------------------------------------------------------------ */
/* Test 1 / 2 / 3 — key parity across EN / LV / NO / RU                */
/* ------------------------------------------------------------------ */

it('keeps EN/LV key parity (no missing, no extra keys)', function (): void {
    $arEnKeys = arrayKeysRecursive(loadLocale('en'));
    $arLvKeys = arrayKeysRecursive(loadLocale('lv'));
    expect($arLvKeys)->toBe($arEnKeys);
});

it('keeps EN/NO key parity (no missing, no extra keys)', function (): void {
    $arEnKeys = arrayKeysRecursive(loadLocale('en'));
    $arNoKeys = arrayKeysRecursive(loadLocale('no'));
    expect($arNoKeys)->toBe($arEnKeys);
});

it('keeps EN/RU key parity (no missing, no extra keys)', function (): void {
    $arEnKeys = arrayKeysRecursive(loadLocale('en'));
    $arRuKeys = arrayKeysRecursive(loadLocale('ru'));
    expect($arRuKeys)->toBe($arEnKeys);
});

/* ------------------------------------------------------------------ */
/* Test 4 — every leaf is a non-empty string in every locale          */
/* ------------------------------------------------------------------ */

it('LV has no empty / null / non-string leaves', function (): void {
    assertAllLeavesNonEmptyString(loadLocale('lv'), 'lv');
});

it('NO has no empty / null / non-string leaves', function (): void {
    assertAllLeavesNonEmptyString(loadLocale('no'), 'no');
});

it('RU has no empty / null / non-string leaves', function (): void {
    assertAllLeavesNonEmptyString(loadLocale('ru'), 'ru');
});

/* ------------------------------------------------------------------ */
/* Test 5 — sample-based smoke: 3 high-visibility keys per locale     */
/*           differ from EN (catches copy-paste-no-translate bug).    */
/* ------------------------------------------------------------------ */

it('LV/NO/RU translate the high-visibility sample keys (not EN copy-paste)', function (): void {
    $arEn = loadLocale('en');
    $arLv = loadLocale('lv');
    $arNo = loadLocale('no');
    $arRu = loadLocale('ru');

    $arSampleKeys = [
        'field.enabled',
        'permission.upload_invoices',
        'apply.button_now',
    ];

    foreach ($arSampleKeys as $sKey) {
        $sEn = digKey($arEn, $sKey);
        expect(digKey($arLv, $sKey))
            ->not->toBe($sEn, "LV key $sKey is identical to EN — translation missing");
        expect(digKey($arNo, $sKey))
            ->not->toBe($sEn, "NO key $sKey is identical to EN — translation missing");
        expect(digKey($arRu, $sKey))
            ->not->toBe($sEn, "RU key $sKey is identical to EN — translation missing");
    }
});

/* ------------------------------------------------------------------ */
/* Test 6 — placeholder preservation (`:size`, `:offer_count`, etc.)   */
/* ------------------------------------------------------------------ */

it('preserves all :placeholder tokens from EN in LV/NO/RU translations', function (): void {
    $arEn = loadLocale('en');
    $arLv = loadLocale('lv');
    $arNo = loadLocale('no');
    $arRu = loadLocale('ru');

    $arEnPlaceholders = collectPlaceholderKeys($arEn);
    expect(count($arEnPlaceholders))->toBeGreaterThan(0, 'sanity: EN must have at least one :placeholder token');

    foreach ($arEnPlaceholders as [$sDotKey, $sToken]) {
        foreach (['lv' => $arLv, 'no' => $arNo, 'ru' => $arRu] as $sLocale => $arData) {
            $sValue = digKey($arData, $sDotKey);
            expect($sValue)->toBeString("Locale $sLocale key $sDotKey missing or non-string");
            expect(str_contains((string) $sValue, $sToken))
                ->toBeTrue("Locale $sLocale key $sDotKey dropped placeholder '$sToken' (got: $sValue)");
        }
    }
});

/* ------------------------------------------------------------------ */
/* Test 7 — typed-confirmation literals OVERRIDE / RESET preserved.    */
/*           Server-side strict-equality tokens — D-19 / D-22.         */
/* ------------------------------------------------------------------ */

it('preserves OVERRIDE / RESET typed-confirmation literals across LV/NO/RU', function (): void {
    foreach (['lv', 'no', 'ru'] as $sLocale) {
        $arData = loadLocale($sLocale);

        $sOverrideHint = digKey($arData, 'override.typed_hint');
        expect($sOverrideHint)->toBeString("Locale $sLocale missing override.typed_hint");
        expect(str_contains((string) $sOverrideHint, 'OVERRIDE'))
            ->toBeTrue("Locale $sLocale override.typed_hint must contain literal 'OVERRIDE' (got: $sOverrideHint)");

        $sResetHint = digKey($arData, 'initial_reset.typed_hint');
        expect($sResetHint)->toBeString("Locale $sLocale missing initial_reset.typed_hint");
        expect(str_contains((string) $sResetHint, 'RESET'))
            ->toBeTrue("Locale $sLocale initial_reset.typed_hint must contain literal 'RESET' (got: $sResetHint)");
    }
});
