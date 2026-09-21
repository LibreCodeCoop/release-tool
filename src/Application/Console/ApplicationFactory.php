<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console;

use LibreCode\ReleaseTool\Application\Console\Command\ConfigValidateCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    private const PACKAGED_VERSION = '@release_tool_version@';

    public static function create(): Application
    {
        $application = new Application('release-tool', self::version());
        $application->setAutoExit(false);
        $application->add(new ConfigValidateCommand());

        return $application;
    }

    public static function version(): string
    {
        return str_starts_with(self::PACKAGED_VERSION, '@')
            ? '0.1.0-dev'
            : self::PACKAGED_VERSION;
    }
}
