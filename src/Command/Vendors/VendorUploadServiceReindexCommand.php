<?php

/**
 * @file
 * Helper to search cover store bulk upload images for missing source entities.
 */

namespace App\Command\Vendors;

use App\Entity\Image;
use App\Entity\Source;
use App\Entity\Vendor;
use App\Message\SearchMessage;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\UploadService\UploadServiceVendorService;
use App\Utils\CoverStore\CoverStoreItem;
use App\Utils\Types\IdentifierType;
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
 * Class VendorUploadServiceReindexCommand.
 */
#[AsCommand(name: 'app:vendor:upload-service-reindex')]
class VendorUploadServiceReindexCommand extends Command
{
    use ProgressBarTrait;

    private const SOURCE_FOLDER = 'UploadService';
    private const VENDOR_ID = UploadServiceVendorService::VENDOR_ID;
    private Vendor $vendor;

    /**
     * VendorJobCommand constructor.
     *
     * @param MessageBusInterface $bus
     *   Message queue bus
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly CoverStoreInterface $coverStore,
        private readonly SourceRepository $sourceRepository,
        private readonly VendorRepository $vendorRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();

        $this->vendor = $this->vendorRepository->findOneBy(['id' => self::VENDOR_ID]);
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Search cover store for bulk upload images that are not in the source table')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'Limit to single identifier.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = $input->getOption('identifier');
        $searchQuery = null;
        if (null !== $identifier) {
            $searchQuery = 'public_id:'.self::SOURCE_FOLDER.'/'.addcslashes($identifier, ':');
        }

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('[%bar%] %elapsed% (%memory%) - %message%');
        $this->setProgressBar($progressBar);
        $this->progressStart('Start search cover store');

        // Search the cover store.
        $found = 0;
        $totalItems = 0;

        if (null === $searchQuery) {
            $items = $this->coverStore->getFolder(self::SOURCE_FOLDER);
        } else {
            $items = $this->coverStore->search($searchQuery, self::SOURCE_FOLDER);
        }
        
        /** @var CoverStoreItem $item */
        foreach ($items as $item) {
            $parts = explode(self::SOURCE_FOLDER.'/', $item->getId(), 2);
            $identifier = end($parts);
            $source = $this->sourceRepository->findOneBy(['matchId' => $identifier, 'vendor' => self::VENDOR_ID]);
            if (is_null($source)) {
                // The type is either ISBN or PID (as they are the only once processed by upload service).
                $type = IdentifierType::ISBN;
                if (str_contains($identifier, ':')) {
                    $type = IdentifierType::PID;
                }

                // Found image in store that is not in the search table.
                // Create new entity.
                $image = new Image();
                $source = new Source();

                // Set image information.
                $image->setImageFormat($item->getImageFormat())
                    ->setSize($item->getSize())
                    ->setWidth($item->getWidth())
                    ->setHeight($item->getHeight())
                    ->setCoverStoreURL($item->getUrl());
                $this->em->persist($image);

                // Set source entity information.
                $source->setMatchType($type)
                    ->setMatchId($identifier)
                    ->setVendor($this->vendor)
                    ->setDate(new \DateTime())
                    ->setOriginalFile($item->getUrl())
                    ->setOriginalContentLength($item->getSize())
                    ->setOriginalLastModified(new \DateTime())
                    ->setImage($image);
                $this->em->persist($source);

                // Make it stick.
                $this->em->flush();

                // Create queue message to get this source indexed.
                $searchMessage = new SearchMessage();
                $searchMessage->setIdentifier($identifier)
                    ->setIdentifierType($type)
                    ->setOperation(VendorState::INSERT)
                    ->setImageId($image->getId())
                    ->setVendorId($this->vendor->getId());
                $this->bus->dispatch($searchMessage);

                ++$found;
            }
            ++$totalItems;

            $this->progressMessage(
                sprintf(
                    'Found missing source %d of %d',
                    number_format($found, 0, ',', '.'),
                    number_format($totalItems, 0, ',', '.')
                )
            );
            $this->progressAdvance();
        }


        $this->progressFinish();

        return Command::SUCCESS;
    }
}
