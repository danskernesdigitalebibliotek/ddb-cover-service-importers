<?php

/**
 * @file
 * Helper command to search the source table for potential new images.
 */

namespace App\Command\Source;

use App\Entity\Source;
use App\Event\VendorEvent;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SourceDownloadCoversCommand extends Command
{
    use ProgressBarTrait;

    protected static $defaultName = 'app:source:download';

    private $em;
    private $validator;
    private $dispatcher;

    public function __construct(EntityManagerInterface $entityManager, VendorImageValidatorService $validator, EventDispatcherInterface $eventDispatcher)
    {
        $this->em = $entityManager;
        $this->validator = $validator;
        $this->dispatcher = $eventDispatcher;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure()
    {
        $this->setDescription('Try to (re-)download source records that have not been downloaded into Cover store')
            ->addOption('vendor-id', null, InputOption::VALUE_OPTIONAL, 'Limit to this vendor')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'Only for this identifier');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $vendorId = $input->getOption('vendor-id');
        $identifier = $input->getOption('identifier');

        $batchSize = 50;
        $i = 0;
        $found = 0;

        $this->progressBar = new ProgressBar($output);
        $this->progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->progressMessage('Search database source table');
        $this->progressBar->start();
        $this->progressBar->advance();

        $queryStr = 'SELECT s FROM App\Entity\Source s WHERE s.image IS NULL AND s.originalFile IS NOT NULL';
        if (!is_null($identifier)) {
            $queryStr .= ' AND s.matchId = '.$identifier;
        }
        if (!is_null($vendorId)) {
            $queryStr .= ' AND s.vendor = '.$vendorId;
        }

        $query = $this->em->createQuery($queryStr);
        $iterableResult = $query->iterate();
        foreach ($iterableResult as $row) {
            /** @var Source $source */
            $source = $row[0];

            $item = new VendorImageItem();
            $item->setOriginalFile($source->getOriginalFile());
            $this->validator->validateRemoteImage($item);

            if ($item->isFound()) {
                ++$found;
                $event = new VendorEvent(VendorState::UPDATE, [$source->getMatchId()], $source->getMatchType(), $source->getVendor()->getId());
                $this->dispatcher->dispatch($event, $event::NAME);
            }

            $this->progressAdvance();
            $this->progressMessage(sprintf('Found: %d. Not found: %d.', $found, ($i + 1) - $found));

            // Free memory when batch size is reached.
            if (0 === ($i % $batchSize)) {
                $this->em->flush();
                $this->em->clear();
            }

            ++$i;
        }

        $this->progressFinish();
        $output->writeln('');

        return 0;
    }
}
