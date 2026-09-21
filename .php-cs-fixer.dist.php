<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src'])
    ->append([__FILE__]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'declare_strict_types' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
