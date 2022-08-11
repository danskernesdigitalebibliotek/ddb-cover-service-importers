<?php

/**
 * @file
 * Console command to search the cover store.
 */

namespace App\Command\CoverStore;

use App\Service\CoverStore\CoverStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CoverStoreSearchCommand.
 */
#[AsCommand(name: 'app:cover:search')]
class CoverStoreSearchCommand extends Command
{
    /**
     * CoverStoreSearchCommand constructor.
     *
     * @param CoverStoreInterface $store
     */
    public function __construct(
        private readonly CoverStoreInterface $store
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Search a folder in the cover store')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Name of the vendor that owns the image (folder in the store)')
            ->addOption('query', null, InputOption::VALUE_OPTIONAL, 'Query to execute in the folder');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = $this->store->search(
            (string) $input->getOption('folder'),
            $input->getOption('query')
        );

        foreach ($items as $item) {
            $output->writeln((string) $item);
        }

        return Command::SUCCESS;
    }
}
