<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console;

use LibreCode\ReleaseTool\Application\Console\Command\ConfigValidateCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommand;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    private const string PACKAGED_VERSION = '@release_tool_version@';

    public static function create(?ReleasePlanning $planner = null): Application
    {
        $application = new Application('release-tool', self::version());
        $application->setAutoExit(false);
        $application->add(new ConfigValidateCommand());

        if ($planner !== null) {
            $application->add(new ReleasePlanCommand($planner));
        }

        return $application;
    }

    public static function version(): string
    {
        return str_starts_with(self::PACKAGED_VERSION, '@')
            ? '0.1.0-dev'
            : self::PACKAGED_VERSION;
    }
}
