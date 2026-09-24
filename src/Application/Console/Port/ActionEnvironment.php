<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Port;

interface ActionEnvironment
{
    public function output(string $name, string $value): void;

    public function summary(string $markdown): void;
}
