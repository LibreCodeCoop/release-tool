<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])->append([__FILE__]);

return (new Config())->setRiskyAllowed(true)->setRules(['@PER-CS2.0' => true, '@PHP83Migration' => true, 'declare_strict_types' => true, 'ordered_imports' => true])->setFinder($finder);
