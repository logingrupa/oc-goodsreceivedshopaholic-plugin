<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\DuplicateInvoiceException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidEanException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\OperatorOverridesActiveFlagException;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-02 Task 2: Exception hierarchy assertions.
 *
 * Asserts:
 *   - Base GoodsReceivedException is abstract (cannot be instantiated directly)
 *   - All 8 typed subclasses extend the base
 *   - Polymorphic catch: catching base catches every subclass
 */

it('declares the base GoodsReceivedException as abstract', function (): void {
    $obReflect = new \ReflectionClass(GoodsReceivedException::class);

    expect($obReflect->isAbstract())->toBeTrue();
});

it('every typed exception extends the base', function (): void {
    /** @var list<class-string<GoodsReceivedException>> $arSubclasses */
    $arSubclasses = [
        InvoiceNumberMissingException::class,
        DuplicateInvoiceException::class,
        InvalidEanException::class,
        InvalidQuantityException::class,
        ApplyAlreadyDoneException::class,
        InitialResetNotAllowedException::class,
        OperatorOverridesActiveFlagException::class,
        MalformedHtmException::class,
    ];

    expect(count($arSubclasses))->toBe(8);

    foreach ($arSubclasses as $sFqcn) {
        $obException = new $sFqcn('test message');
        expect($obException)->toBeInstanceOf(GoodsReceivedException::class);
        expect($obException)->toBeInstanceOf(\RuntimeException::class);
        expect($obException->getMessage())->toBe('test message');
    }
});

it('polymorphic catch: catching base catches all 8 subclasses', function (): void {
    /** @var list<class-string<GoodsReceivedException>> $arSubclasses */
    $arSubclasses = [
        InvoiceNumberMissingException::class,
        DuplicateInvoiceException::class,
        InvalidEanException::class,
        InvalidQuantityException::class,
        ApplyAlreadyDoneException::class,
        InitialResetNotAllowedException::class,
        OperatorOverridesActiveFlagException::class,
        MalformedHtmException::class,
    ];

    $arCaught = [];
    foreach ($arSubclasses as $sFqcn) {
        try {
            throw new $sFqcn('boom');
        } catch (GoodsReceivedException $obException) {
            $arCaught[] = $sFqcn;
        }
    }

    expect(count($arCaught))->toBe(8);
    expect($arCaught)->toBe($arSubclasses);
});

it('every typed subclass is final', function (): void {
    /** @var list<class-string<GoodsReceivedException>> $arSubclasses */
    $arSubclasses = [
        InvoiceNumberMissingException::class,
        DuplicateInvoiceException::class,
        InvalidEanException::class,
        InvalidQuantityException::class,
        ApplyAlreadyDoneException::class,
        InitialResetNotAllowedException::class,
        OperatorOverridesActiveFlagException::class,
        MalformedHtmException::class,
    ];

    foreach ($arSubclasses as $sFqcn) {
        $obReflect = new \ReflectionClass($sFqcn);
        expect($obReflect->isFinal())->toBeTrue();
    }
});
