<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Release\PlanReleaseInput;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use Symfony\Component\Console\Input\InputInterface;

final readonly class ReleasePlanCommandRequest
{
    public function __construct(
        public string $configPath,
        public string $root,
        public PlanReleaseInput $planInput,
        public ReleasePlanOutputOptions $output,
    ) {
    }

    public static function fromInput(InputInterface $input): self
    {
        return new self(
            configPath: (string) $input->getOption('config'),
            root: (string) $input->getOption('root'),
            planInput: new PlanReleaseInput(
                branch: self::requiredString($input, 'branch'),
                ref: self::optionalString($input->getOption('ref')),
                versionOverride: self::optionalString($input->getOption('release-version')),
                channel: self::enumOption($input, 'channel', ReleaseChannel::class, 'alpha, beta, rc or final'),
                ignoreOpenBackport: (bool) $input->getOption('ignore-open-backport'),
                createFollowUpMilestone: (bool) $input->getOption('create-follow-up-milestone'),
                mode: self::enumOption($input, 'mode', ReleaseMode::class, 'normal or security'),
                safePublicText: self::optionalString($input->getOption('safe-public-text')),
            ),
            output: ReleasePlanOutputOptions::fromInput($input),
        );
    }

    private static function requiredString(InputInterface $input, string $name): string
    {
        $value = self::optionalString($input->getOption($name));
        if ($value === null) {
            throw new InvalidArgumentException(sprintf('--%s is required.', $name));
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    private static function enumOption(
        InputInterface $input,
        string $name,
        string $enum,
        string $expected,
    ): \BackedEnum {
        $value = (string) $input->getOption($name);
        $resolved = $enum::tryFrom($value);
        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf('Invalid --%s; expected %s.', $name, $expected));
        }

        return $resolved;
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
