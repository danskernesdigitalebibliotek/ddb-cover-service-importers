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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class SearchPopulateCommand.
 */
class SearchPopulateCommand extends Command
{
    private $populateService;

    protected static $defaultName = 'app:search:populate';

    /**
     * SearchPopulateCommand constructor.
     *
     * @param \App\Service\PopulateService $populateService
     */
    public function __construct(PopulateService $populateService)
    {
        $this->populateService = $populateService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Populate the search index with data from the search table.')
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'The index to populate.')
            ->addOption('run_id', null, InputOption::VALUE_OPTIONAL, 'The id of the run. Use this to continue an aborted run. Defaults to current timestamp.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $index = $input->getOption('index');

        if (!$index) {
            throw new \RuntimeException('Index must be specified.');
        }

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');

        $this->populateService->setProgressBar($progressBar);
        $this->populateService->populate($index);

        return 0;
    }
}
