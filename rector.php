<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpSets(php83: true)
    ->withSkip([
        Rector\CodeQuality\Rector\Class_\ReadonlyClassRector::class,
        Rector\CodeQuality\Rector\Property\ReadOnlyPropertyRector::class,
        Rector\Php83\Rector\ClassConst\AddTypeToConstRector::class,
        Rector\Php80\Rector\Class_\StringableForToStringRector::class,
    ]);
