<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console;

use LibreCode\ReleaseTool\Application\Console\Command\ConfigValidateCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public const NAME = 'release-tool';
    public const VERSION = '0.1.0-dev';

    public static function create(): Application
    {
        $application = new Application(self::NAME, self::VERSION);
        $application->setAutoExit(false);
        $application->add(new ConfigValidateCommand());

        return $application;
    }
}
