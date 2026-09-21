<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Configuration;

use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;

interface ConsumerConfigContextValidator
{
    public function validate(ConsumerConfig $config, string $root): void;
}
