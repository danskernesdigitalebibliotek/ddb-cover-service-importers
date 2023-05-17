<?php

namespace App\Command\Index;

use App\Exception\SearchIndexException;
use App\Service\Indexing\IndexingServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:index:create',
    description: 'Create ES index if it doesnt exist',
)]
class IndexCreateCommand extends Command
{
    /**
     * SearchPopulateCommand constructor.
     *
     * @param IndexingServiceInterface $indexingService
     */
    public function __construct(
        private readonly IndexingServiceInterface $indexingService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (!$this->indexingService->indexExists()) {
                $this->indexingService->createIndex();

                $io->success('Index created');
            } else {
                $io->caution('Index exists. Aborting.');
            }
        } catch (SearchIndexException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
