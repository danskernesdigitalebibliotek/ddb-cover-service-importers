<?php
/**
 * @file
 * Contains a command to populate the search index.
 */

namespace App\Command;

use App\Service\PopulateService;
use App\Service\VendorService\ProgressBarTrait;
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
    use ProgressBarTrait;

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
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force execution ignoring locks')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Single search table record id (try populate single record)', -1);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getOption('id');
        $force = $input->getOption('force');

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->setProgressBar($progressBar);
        $this->progressStart('Starting populate process');

        foreach ($this->populateService->populate($id, $force) as $message) {
            $this->progressMessage($message);
            $this->progressAdvance();
        };

        $this->progressFinish();

        // Start the command line on a new line.
        $output->writeln('');

        return Command::SUCCESS;
    }
}
