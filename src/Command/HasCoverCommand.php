<?php
/**
 * @file
 * Helper command to insert message into queue system.
 */

namespace App\Command;

use App\Entity\Search;
use App\Message\HasCoverMessage;
use App\Repository\SearchRepository;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class HasCoverCommand.
 */
#[AsCommand(name: 'app:hascover:batch')]
class HasCoverCommand extends Command
{
    use ProgressBarTrait;

    /**
     * @param MessageBusInterface $bus
     * @param EntityManagerInterface $entityManager
     * @param SearchRepository $searchRepository
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchRepository $searchRepository
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Send batch of materials to hasCover service')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The number of records to load from the database')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'The offset to start loading records from the database')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'If set only this material will be send');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = intval($input->getOption('limit'));
        $offset = intval($input->getOption('offset'));
        $identifier = $input->getOption('identifier');

        $section = $output->section('Sheet');
        $progressBarSheet = new ProgressBar($section);
        $progressBarSheet->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->setProgressBar($progressBarSheet);
        $this->progressStart('Loading database source (from '.$offset.')');
        $batchSize = 200;
        $i = 1;

        $query = $this->searchRepository->findSearchesByType(IdentifierType::PID, $identifier, $limit, $offset);

        /* @var Search $search */
        foreach ($query->toIterable() as $search) {
            $hasCoverMessage = new HasCoverMessage();
            $hasCoverMessage->setPid($search->getIsIdentifier())->setCoverExists(true);
            $this->bus->dispatch($hasCoverMessage);

            // Free memory when batch size is reached.
            if (0 === ($i % $batchSize)) {
                $this->entityManager->clear();
                gc_collect_cycles();
            }

            $this->progressAdvance();
            $this->progressMessage($i.' search(es) found in DB');
            ++$i;
        }

        $this->progressFinish();

        return Command::SUCCESS;
    }
}
