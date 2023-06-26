<?php

/**
 * @file
 * Console command to search the cover store.
 */

namespace App\Command\CoverStore;

use App\Exception\CoverStoreException;
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
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Query to execute in the folder')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Name of the vendor that owns the image (folder in the store)', null)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of results', 10);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = $input->getOption('query');
        if (false === $query || null === $query) {
            $output->writeln('<error>Please provide a query to execute</error>');

            return Command::FAILURE;
        }

        $limit = intval($input->getOption('limit'));
        if ($limit <= 0) {
            $output->writeln('<error>Limit must a positive integer</error>');

            return Command::FAILURE;
        }

        $folder = $input->getOption('folder');

        try {
            $items = $this->store->search($query, $folder, $limit);

            foreach ($items as $item) {
                $output->writeln((string) $item);
            }

            return Command::SUCCESS;
        } catch (CoverStoreException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
    }
}
