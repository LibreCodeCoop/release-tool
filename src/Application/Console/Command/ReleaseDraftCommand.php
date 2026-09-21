<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\MilestoneTransitionCodec;
use LibreCode\ReleaseTool\Application\Release\PreparedReleaseCodec;
use LibreCode\ReleaseTool\Application\Release\ReleaseDraftCodec;
use LibreCode\ReleaseTool\Application\Release\ReleaseDrafter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:draft', description: 'Create or update a GitHub Release draft from finalized release artifacts.')]
final class ReleaseDraftCommand extends Command
{
    public function __construct(
        private readonly ReleaseDrafter $drafter,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly PreparedReleaseCodec $preparedCodec = new PreparedReleaseCodec(),
        private readonly MilestoneTransitionCodec $milestoneCodec = new MilestoneTransitionCodec(),
        private readonly ReleaseDraftCodec $draftCodec = new ReleaseDraftCodec(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prepared', null, InputOption::VALUE_REQUIRED, 'PreparedRelease v1 JSON path.')
            ->addOption('milestone', null, InputOption::VALUE_REQUIRED, 'MilestoneTransition v1 JSON path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit ReleaseDraft v1 JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $prepared = $this->readRequired((string) $input->getOption('prepared'), '--prepared');
            $milestone = $this->readRequired((string) $input->getOption('milestone'), '--milestone');
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $draft = $this->drafter->prepare(
                $config,
                $this->preparedCodec->decode($prepared),
                $this->milestoneCodec->decode($milestone),
            );
        } catch (\Throwable $exception) {
            if ((bool) $input->getOption('json')) {
                $output->writeln((string) json_encode(['schema' => 1, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');
            }
            return Command::INVALID;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->draftCodec->encode($draft));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Draft: %s', $draft->releaseUrl));
        $output->writeln(sprintf('Tag: %s', $draft->tagName));
        $output->writeln(sprintf('Target: %s', $draft->targetSha));
        return Command::SUCCESS;
    }

    private function readRequired(string $path, string $option): string
    {
        if (trim($path) === '') {
            throw new \InvalidArgumentException($option . ' is required.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Unable to read artifact: %s', $path));
        }
        return $content;
    }
}
