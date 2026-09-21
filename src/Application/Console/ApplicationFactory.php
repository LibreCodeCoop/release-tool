<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console;

use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Console\Command\ArtifactValidateCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ConfigValidateCommand;
use LibreCode\ReleaseTool\Application\Console\Command\MetadataInspectCommand;
use LibreCode\ReleaseTool\Application\Console\Command\MilestoneTransitionCommand;
use LibreCode\ReleaseTool\Application\Console\Command\PublicationVerifyCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleaseDraftCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleaseFinalizeCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePrepareCommand;
use LibreCode\ReleaseTool\Application\Release\LocalReleaseMetadataInspector;
use LibreCode\ReleaseTool\Application\Release\MilestoneTransitioner;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\ReleaseDrafter;
use LibreCode\ReleaseTool\Application\Release\ReleaseFinalizer;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use LibreCode\ReleaseTool\Application\Publication\PublicationVerifier;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparationPublishing;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparer;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    private const string PACKAGED_VERSION = '@release_tool_version@';

    public static function create(
        ?ReleasePlanning $planner = null,
        ?ConsumerConfigContextValidator $configValidator = null,
        ?ReleasePreparer $preparer = null,
        ?ReleasePreparationPublishing $preparationPublisher = null,
        ?GitRepository $gitRepository = null,
        ?LocalReleaseMetadataInspector $metadataInspector = null,
        ?ReleaseFinalizer $finalizer = null,
        ?MilestoneTransitioner $milestoneTransitioner = null,
        ?ReleaseDrafter $releaseDrafter = null,
        ?ArtifactValidator $artifactValidator = null,
        ?PublicationVerifier $publicationVerifier = null,
    ): Application
    {
        $application = new Application('release-tool', self::version());
        $application->setAutoExit(false);
        $application->add(new ConfigValidateCommand(
            contextValidator: $configValidator ?? new NoopConsumerConfigContextValidator(),
        ));

        if ($artifactValidator !== null) {
            $application->add(new ArtifactValidateCommand(
                $artifactValidator,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($publicationVerifier !== null) {
            $application->add(new PublicationVerifyCommand(
                $publicationVerifier,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($gitRepository !== null && $metadataInspector !== null) {
            $application->add(new MetadataInspectCommand(
                $gitRepository,
                $metadataInspector,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($finalizer !== null) {
            $application->add(new ReleaseFinalizeCommand(
                $finalizer,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($milestoneTransitioner !== null) {
            $application->add(new MilestoneTransitionCommand(
                $milestoneTransitioner,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($releaseDrafter !== null) {
            $application->add(new ReleaseDraftCommand(
                $releaseDrafter,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($planner !== null) {
            $application->add(new ReleasePlanCommand(
                $planner,
                contextValidator: $configValidator ?? new NoopConsumerConfigContextValidator(),
            ));
        }

        if ($preparer !== null) {
            $application->add(new ReleasePrepareCommand(
                $preparer,
                $configValidator ?? new NoopConsumerConfigContextValidator(),
                $preparationPublisher,
            ));
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
