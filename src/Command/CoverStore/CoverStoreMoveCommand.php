<?php

/**
 * @file
 * Console command to move resources in the cover store.
 */

namespace App\Command\CoverStore;

use App\Service\CoverStore\CoverStoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CoverStoreMoveCommand.
 */
class CoverStoreMoveCommand extends Command
{
    protected static $defaultName = 'app:cover:move';

    private CoverStoreInterface $store;

    /**
     * CoverStoreMoveCommand constructor.
     *
     * @param CoverStoreInterface $store
     */
    public function __construct(CoverStoreInterface $store)
    {
        $this->store = $store;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Move a cover in the cover store')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Resource id for the source "folder/image-name"')
            ->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Resource id for the distination "folder/image-name"');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getOption('source');
        $destination = (string) $input->getOption('destination');

        $item = $this->store->move($source, $destination);

        // If not moved exceptions should have been thrown.
        $output->writeln($item);

        return 0;
    }
}
