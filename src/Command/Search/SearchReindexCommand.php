<?php

/**
 * @file
 * Reindex data in the search table base on vendor.
 */

namespace App\Command\Search;

use App\Entity\Source;
use App\Message\SearchMessage;
use App\Repository\SourceRepository;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SearchReindexCommand extends Command
{
    use ProgressBarTrait;

    protected static $defaultName = 'app:search:reindex';

    private EntityManagerInterface $em;
    private MessageBusInterface $bus;

    /**
     * SearchReindexCommand constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param MessageBusInterface $bus
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus)
    {
        $this->em = $entityManager;
        $this->bus = $bus;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Reindex search table')
            ->addOption('vendor-id', null, InputOption::VALUE_OPTIONAL, 'Limit the re-index to vendor with this id number')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'If set only this identifier will be re-index (requires that you set vendor id)')
            ->addOption('clean-up', null, InputOption::VALUE_NONE, 'Remove all rows from the search table related to a given source before insert')
            ->addOption('without-search-cache', null, InputOption::VALUE_NONE, 'If set do not use search cache during re-index')
            ->addOption('last-indexed-date', null, InputOption::VALUE_OPTIONAL, 'The date used when re-indexing in batches to have keeps track index by date (24-10-2021)')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size to index, requires an last-indexed-date is given');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = $input->getOption('vendor-id');
        $cleanUp = $input->getOption('clean-up');
        $identifier = $input->getOption('identifier');
        $withOutSearchCache = $input->getOption('without-search-cache');
        $lastIndexedDate = $input->getOption('last-indexed-date');
        $batchSize = $input->getOption('batch-size');

        $inputDate = null;
        if (!is_null($identifier)) {
            if (is_null($vendorId)) {
                $output->writeln('<error>Missing vendor id required in combination with identifier</error>');

                return 1;
            }
        }

        if (!is_null($batchSize)) {
            if (is_null($lastIndexedDate)) {
                $output->writeln('<error>Batch size can not be given without last-indexed-date</error>');

                return -1;
            }

            $format = 'd-m-Y';
            $inputDate = \DateTime::createFromFormat($format, $lastIndexedDate);
            if (!($inputDate && $inputDate->format($format) == $lastIndexedDate)) {
                $output->writeln('<error>Lasted indexed date should have the format "m-d-Y"</error>');

                return -1;
            }
        } else {
            $batchSize = 200;
        }

        // Progress bar setup.
        $section = $output->section('Sheet');
        $progressBarSheet = new ProgressBar($section);
        $progressBarSheet->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->setProgressBar($progressBarSheet);
        $this->progressStart('Loading database source');

        /** @var SourceRepository $sourceRepos */
        $sourceRepos = $this->em->getRepository(Source::class);
        $paginator = $sourceRepos->findReindexabledSources($batchSize, $inputDate, $vendorId, $identifier);
        $i = 0;

        // This loop should run until it's broken from inside. This is to enabled batching over all sources in batches
        // of $batchSize if $lastIndexed data is not give. But at the same time loop over one batch of size given when
        // date is given.
        while (true) {
            /* @var Source $source */
            foreach ($paginator as $source) {
                // Build and create new search job which will trigger index event.
                $message = new SearchMessage();
                $message->setIdentifier($source->getMatchId())
                    ->setOperation(true === $cleanUp ? VendorState::DELETE_AND_UPDATE : VendorState::UPDATE)
                    ->setIdentifierType($source->getMatchType())
                    ->setVendorId($source->getVendor()->getId())
                    ->setImageId($source->getImage()->getId())
                    ->setUseSearchCache(!$withOutSearchCache);
                $this->bus->dispatch($message);

                // Free memory every 200 iterations.
                if (0 === ($i % 200)) {
                    $this->em->clear();
                    gc_collect_cycles();
                }

                ++$i;
                $this->progressAdvance();
                $this->progressMessage('Source rows found '.$i.' in DB');
            }

            // Refresh the paginator to the next set, if date is not given.
            if (is_null($inputDate) && 0 === ($i % $batchSize) && $paginator->count() > $i) {
                $paginator = $sourceRepos->findReindexabledSources($batchSize, $inputDate, $vendorId, $identifier);
            } else {
                break;
            }
        }

        $this->progressFinish();

        return 0;
    }
}
