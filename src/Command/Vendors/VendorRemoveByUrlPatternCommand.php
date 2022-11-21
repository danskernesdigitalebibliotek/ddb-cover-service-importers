<?php

namespace App\Command\Vendors;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:vendor:remove-by-url-pattern',
    description: 'Add a short description for your command',
)]
class VendorRemoveByUrlPatternCommand extends Command
{

    // @todo Find existing and remove them
    // SELECT * FROM `cover-service`.`source` WHERE (`vendor_id` = 14) AND (`original_file` LIKE '%{00000000-0000-0000-0000-000%') ORDER BY `original_file` DESC LIMIT 300 OFFSET 0;
    // SELECT DISTINCT(original_file) FROM `cover-service`.`source` WHERE (`vendor_id` = 14) AND (`original_file` LIKE '%{00000000-0000-0000-0000-000%') ORDER BY `original_file` DESC LIMIT 300 OFFSET 0;

    protected function configure(): void
    {
        $this
            ->addArgument('vendorId', InputArgument::REQUIRED, 'Vendor id found in the database');
            
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arg1 = $input->getArgument('arg1');

        if ($arg1) {
            $io->note(sprintf('You passed an argument: %s', $arg1));
        }

        if ($input->getOption('option1')) {
            // ...
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
