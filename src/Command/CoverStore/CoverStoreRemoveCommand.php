<?php

/**
 * @file
 * Console command to remove item from cover store.
 */

namespace App\Command\CoverStore;

use App\Service\CoverStore\CoverStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class OpenPlatformAuthCommand.
 */
#[AsCommand(name: 'app:cover:remove')]
class CoverStoreRemoveCommand extends Command
{
    /**
     * CoverStoreUploadCommand constructor.
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
        $this->setDescription('Remove image to cover store')
          ->addArgument('folder', InputArgument::REQUIRED, 'Name of the vendor that owns the image')
          ->addArgument('identifier', InputArgument::REQUIRED, 'Identifier for the material to search for');
    }

    /**
     * {@inheritdoc}
     *
     * @throws \App\Exception\CoverStoreAlreadyExistsException
     * @throws \App\Exception\CoverStoreCredentialException
     * @throws \App\Exception\CoverStoreException
     * @throws \App\Exception\CoverStoreNotFoundException
     * @throws \App\Exception\CoverStoreTooLargeFileException
     * @throws \App\Exception\CoverStoreUnexpectedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->store->remove($input->getArgument('folder'), $input->getArgument('identifier'));

        $output->writeln('Item have been removed');

        return Command::SUCCESS;
    }
}
