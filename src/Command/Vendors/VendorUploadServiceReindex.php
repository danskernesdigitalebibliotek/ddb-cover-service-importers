<?php

/**
 * @file
 * Helper command to send single vendor import job into the queue system.
 */

namespace App\Command\Vendors;

use App\Entity\Image;
use App\Entity\Source;
use App\Entity\Vendor;
use App\Message\SearchMessage;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
use App\Service\CoverStore\CoverStoreInterface;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class VendorJobCommand.
 */
#[AsCommand(name: 'app:vendor:upload-service-reindex')]
class VendorUploadServiceReindex extends Command
{
    private const SOURCE_FOLDER = 'UploadService';
    private const VENDOR_ID = 12;
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

        // Search the cover store.
        $items = $this->coverStore->search(self::SOURCE_FOLDER, $searchQuery, true);
        while ($items) {
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
                }
            }

            $items = $this->coverStore->search(self::SOURCE_FOLDER, $searchQuery, true);
        }

        return Command::SUCCESS;
    }
}
