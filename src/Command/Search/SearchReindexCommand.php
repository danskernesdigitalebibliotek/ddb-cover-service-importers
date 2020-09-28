<?php

/**
 * @file
 * Reindex data in the search table base on vendor.
 */

namespace App\Command\Search;

use App\Entity\Source;
use App\Message\SearchMessage;
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

    private $em;
    private $bus;

    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus)
    {
        $this->em = $entityManager;
        $this->bus = $bus;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure()
    {
        $this->setDescription('Reindex search table')
            ->addOption('vendor-id', null, InputOption::VALUE_OPTIONAL, 'Limit the re-index to vendor with this id number')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'If set only this identifier will be re-index (requires that you set vendor id)')
            ->addOption('clean-up', null, InputOption::VALUE_NONE, 'Remove all rows from the search table related to a given source before insert')
            ->addOption('without-search-cache', null, InputOption::VALUE_NONE, 'If set do not use search cache during re-index');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vendorId = $input->getOption('vendor-id');
        $cleanUp = $input->getOption('clean-up');
        $identifier = $input->getOption('identifier');
        $withOutSearchCache = $input->getOption('without-search-cache');

        $batchSize = 200;
        $i = 0;

        // @TODO: Move into repository and use query builder.
        $query = 'SELECT s FROM App\Entity\Source s WHERE s.image IS NOT NULL';
        if (!is_null($identifier)) {
            if (!is_null($vendorId)) {
                $query .= ' AND s.matchId = \''.$identifier.'\'';
            } else {
                $output->writeln('<error>Missing vendor id required in combination with identifier</error>');

                return 1;
            }
        }
        if (!is_null($vendorId)) {
            $query .= ' AND s.vendor = '.$vendorId;
        }

        // Progress bar setup.
        $section = $output->section('Sheet');
        $progressBarSheet = new ProgressBar($section);
        $progressBarSheet->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->setProgressBar($progressBarSheet);
        $this->progressStart('Loading database source table in batches of '.$batchSize.' records');

        $query = $this->em->createQuery($query);
        $iterableResult = $query->iterate();
        foreach ($iterableResult as $row) {
            /* @var Source $source */
            $source = $row[0];

            // Build and create new search job which will trigger index event.
            $message = new SearchMessage();
            $message->setIdentifier($source->getMatchId())
                ->setOperation(true === $cleanUp ? VendorState::DELETE_AND_UPDATE : VendorState::UPDATE)
                ->setIdentifierType($source->getMatchType())
                ->setVendorId($source->getVendor()->getId())
                ->setImageId($source->getImage()->getId())
                ->setUseSearchCache(!$withOutSearchCache);
            $this->bus->dispatch($message);

            // Free memory when batch size is reached.
            if (0 === ($i % $batchSize)) {
                $this->em->clear();
                gc_collect_cycles();
            }

            ++$i;

            $this->progressAdvance();
            $this->progressMessage('Source rows found '.$i.' in DB');
        }

        $this->progressFinish();
    }
}
