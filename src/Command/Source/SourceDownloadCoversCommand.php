<?php

/**
 * @file
 * Helper command to search the source table for potential new images.
 */

namespace App\Command\Source;

use App\Entity\Source;
use App\Message\VendorImageMessage;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class SourceDownloadCoversCommand.
 */
#[AsCommand(name: 'app:source:download')]
class SourceDownloadCoversCommand extends Command
{
    use ProgressBarTrait;

    /**
     * SourceDownloadCoversCommand constructor.
     *
     * @param EntityManagerInterface $em
     *   The entity manager to access that database
     * @param VendorImageValidatorService $validator
     *   Image validation service used to detected remote cover
     * @param MessageBusInterface $bus
     *   Message bus to send messages (jobs)
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VendorImageValidatorService $validator,
        private readonly MessageBusInterface $bus
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Try to (re-)download source records that have not been downloaded into Cover store')
            ->addOption('vendor-id', null, InputOption::VALUE_OPTIONAL, 'Limit to this vendor')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'Only for this identifier')
            ->addOption('size', null, InputOption::VALUE_OPTIONAL, 'Limit to files bigger than this size i bytes (content-length from the database)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = $input->getOption('vendor-id');
        $identifier = $input->getOption('identifier');
        $size = $input->getOption('size');

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
            $queryStr .= ' AND s.matchId = \''.$identifier.'\'';
        }
        if (!is_null($vendorId)) {
            $queryStr .= ' AND s.vendor = '.$vendorId;
        }
        if (!is_null($size)) {
            $queryStr .= ' AND s.originalContentLength > '.$size;
        }

        $query = $this->em->createQuery($queryStr);

        /** @var Source $source */
        foreach ($query->toIterable() as $source) {
            $item = new VendorImageItem();
            $item->setOriginalFile($source->getOriginalFile());
            $this->validator->validateRemoteImage($item);

            if ($item->isFound()) {
                ++$found;
                $message = new VendorImageMessage();
                $message->setOperation(VendorState::UPDATE)
                    ->setIdentifier($source->getMatchId())
                    ->setVendorId($source->getVendor()->getId())
                    ->setIdentifierType($source->getMatchType());

                // Check that the image is set on the source record if not, this needs to be an insert. This can happen
                // if cover store have failed to download the image in earlier attempts.
                if (is_null($source->getImage())) {
                    $message->setOperation(VendorState::INSERT);
                }

                $this->bus->dispatch($message);
            }

            $this->progressAdvance();
            $this->progressMessage(sprintf('Found: %d. Not found: %d.', $found, ($i + 1) - $found));

            // Free memory when batch size is reached.
            if (0 === ($i % $batchSize)) {
                $this->em->clear();
            }

            ++$i;
        }

        // Finish progress-bar and start the command line on a new line.
        $this->progressFinish();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
