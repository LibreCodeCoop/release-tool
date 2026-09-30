<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\Exception\ReleasePlanConfigurationFailure;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommandRequest;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;

final readonly class ReleasePlanUseCase
{
    public function __construct(
        private ReleasePlanning $planner,
        private ConsumerConfigLoader $configLoader,
        private ConsumerConfigContextValidator $contextValidator,
    ) {
    }

    public function execute(ReleasePlanCommandRequest $request): ReleasePlan
    {
        try {
            $config = $this->configLoader->load($request->configPath);
            $this->contextValidator->validate($config, $request->root);
        } catch (InvalidArgumentException $exception) {
            throw new ReleasePlanConfigurationFailure($exception->getMessage(), 0, $exception);
        }

        return $this->planner->plan($config, $request->planInput);
    }
}
