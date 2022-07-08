<?php
/**
 * @file
 * Console command to populate vendor config table with missing vendors services.
 */

namespace App\Command\Vendors;

use App\Service\VendorService\VendorServiceFactory;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class VendorPopulateCommand.
 */
#[AsCommand(name: 'app:vendor:populate')]
class VendorPopulateCommand extends Command
{
    /**
     * VendorPopulateCommand constructor.
     *
     * @param VendorServiceFactory $vendorFactory
     */
    public function __construct(
        private readonly VendorServiceFactory $vendorFactory
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Populate vendor options table in DB with missing vendor services.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $inserted = $this->vendorFactory->populateVendors();

            $io->success('👍 '.$inserted.' vendors inserted.');
        } catch (Exception $exception) {
            $io->error('👎 '.$exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
