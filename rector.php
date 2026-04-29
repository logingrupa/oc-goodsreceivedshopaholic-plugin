<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/classes',
        __DIR__ . '/components',
        __DIR__ . '/models',
        __DIR__ . '/Plugin.php',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/lang',
        __DIR__ . '/updates',
        __DIR__ . '/tests',
        __DIR__ . '/partials',
        __DIR__ . '/assets',
    ]);
