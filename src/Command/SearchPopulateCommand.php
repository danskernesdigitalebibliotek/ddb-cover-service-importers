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
use Symfony\Component\Lock\LockFactory;

/**
 * Class SearchPopulateCommand.
 */
class SearchPopulateCommand extends Command
{
    private PopulateService $populateService;
    private LockFactory $lockFactory;

    protected static $defaultName = 'app:search:populate';

    /**
     * SearchPopulateCommand constructor.
     *
     * @param PopulateService $populateService
     * @param LockFactory $lockFactory
     */
    public function __construct(PopulateService $populateService, LockFactory $lockFactory)
    {
        $this->populateService = $populateService;
        parent::__construct();
        $this->lockFactory = $lockFactory;
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
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getOption('id');
        $force = $input->getOption('force');

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->populateService->setProgressBar($progressBar);

        // Get lock with an TTL of 1 hour, which should be more than enough to populate ES.
        $lock = $this->lockFactory->createLock('app:search:populate:lock', 3600, false);

        if ($lock->acquire() || $force) {
            $this->populateService->populate($id);
            $lock->release();
        } else {
            $output->write('<error>Process is already running use "--force" to run command</error>');
        }

        // Start the command line on a new line.
        $output->writeln('');

        return 0;
    }
}
