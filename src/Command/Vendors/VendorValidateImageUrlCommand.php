<?php

/**
 * @file
 * Helper command to debug remote image validation.
 */

namespace App\Command\Vendors;

use App\Repository\VendorRepository;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class VendorValidateImageUrlCommand.
 */
#[AsCommand(name: 'app:vendor:image-validation')]
class VendorValidateImageUrlCommand extends Command
{
    public function __construct(
        private readonly VendorImageValidatorService $imageValidatorService,
        private readonly VendorRepository $vendorRepository
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Validate remote url using vendor validator')
            ->addOption('vendor-id', null, InputOption::VALUE_REQUIRED, 'Vendor id found in the database')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Remote URL to run validation on');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        $vendorId = (int) $input->getOption('vendor-id');

        $vendor = $this->vendorRepository->findOneBy(['id' => $vendorId]);

        $image = new VendorImageItem($url, $vendor);
        $this->imageValidatorService->validateRemoteImage($image);

        $output->writeln($image->__toString());

        if ($image->isFound()) {
            $output->writeln('<info>Valid</info>');

            return Command::SUCCESS;
        } else {
            $output->writeln('<error>Not valid</error>');

            return Command::FAILURE;
        }
    }
}
