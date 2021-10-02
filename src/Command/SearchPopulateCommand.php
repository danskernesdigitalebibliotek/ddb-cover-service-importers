<?php
/**
 * @file
 * Contains a command to populate the search index.
 */

namespace App\Command;

use App\Service\PopulateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SearchPopulateCommand.
 */
class SearchPopulateCommand extends Command
{
    private PopulateService $populateService;

    protected static $defaultName = 'app:search:populate';

    /**
     * SearchPopulateCommand constructor.
     *
     * @param PopulateService $populateService
     */
    public function __construct(PopulateService $populateService)
    {
        $this->populateService = $populateService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Populate the search index with data from the search table.')
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'The index to populate.')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Single search table record id (try populate single record)', -1);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index = (string) $input->getOption('index');
        $id = (int) $input->getOption('id');

        if (!$index) {
            throw new \RuntimeException('Index must be specified.');
        }

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');

        $this->populateService->setProgressBar($progressBar);
        $this->populateService->populate($index, $id);

        // Start the command line on a new line.
        $output->writeln('');

        return 0;
    }
}
