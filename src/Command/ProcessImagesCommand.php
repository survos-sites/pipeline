<?php

declare(strict_types=1);

namespace App\Command;

use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:process', 'Show registered AI workflow tasks. The old JSON-file pipeline runner has been retired.')]
final class ProcessImagesCommand extends Command
{
    public function __construct(
        private readonly TaskRegistry $taskRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tasks = $this->taskRegistry->allMeta();

        $io->title('AI Workflow Tasks');

        if ($tasks === []) {
            $io->warning('No AI workflow tasks are registered.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tasks as $taskName => $meta) {
            $rows[] = [
                $taskName,
                $meta['agent'] ?? '',
                $meta['template'] ?? '',
            ];
        }

        $io->table(['Task', 'Agent', 'Template'], $rows);
        $io->note('The old app:process JSON-file pipeline runner has been retired. Use ai-workflow subjects/tasks or the admin task browser.');

        return Command::SUCCESS;
    }
}
