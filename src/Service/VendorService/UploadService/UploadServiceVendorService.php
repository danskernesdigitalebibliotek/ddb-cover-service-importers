<?php
/**
 * @file
 * Upload service to handle user upload images.
 */

namespace App\Service\VendorService\UploadService;

use App\Entity\Image;
use App\Entity\Source;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\ProcessMessage;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\ProducerInterface;
use Enqueue\Util\JSON;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class UploadServiceVendorService.
 */
class UploadServiceVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 12;

    protected const SOURCE_FOLDER = 'BulkUpload';
    protected const DESTINATION_FOLDER = 'UploadService';

    /** @var CoverStoreInterface $store */
    private $store;

    /** @var ProducerInterface $producer */
    private $producer;

    /**
     * CoverStoreSearchCommand constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     * @param CoverStoreInterface $store
     * @param ProducerInterface $producer
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $statsLogger, CoverStoreInterface $store, ProducerInterface $producer)
    {
        $this->store = $store;
        $this->producer = $producer;

        parent::__construct($eventDispatcher, $entityManager, $statsLogger);
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        $this->progressStart('Searching CoverStore BulkUpload folder for new images');

        // Search
        $items = $this->store->search(self::SOURCE_FOLDER);

        $inserted = 0;
        foreach ($items as $item) {
            $filename = $this->extractFilename($item->getId());

            try {
                $item = $this->store->move($item->getId(), self::DESTINATION_FOLDER.'/'.$filename);
            } catch (\Exception $e) {
                // The image may have been moved to we ignore this error an goes to the next item.
                continue;
            }

            // Get identifier from the image id.
            $identifier = $this->filenameToIdentifier($filename);
            $type = $this->identifierToType($identifier);

            // Create image entity.
            $image = new Image();
            $image->setImageFormat($item->getImageFormat())
                ->setSize($item->getSize())
                ->setWidth($item->getWidth())
                ->setHeight($item->getHeight())
                ->setCoverStoreURL($item->getUrl())
                ->setAutoGenerated(false);
            $this->em->persist($image);

            // Create source entity.
            $source = new Source();
            $source->setMatchType($type)
                ->setMatchId($identifier)
                ->setVendor($this->getVendor())
                ->setDate(new \DateTime())
                ->setOriginalFile($item->getUrl())
                ->setOriginalContentLength($item->getSize())
                ->setOriginalLastModified(new \DateTime())
                ->setImage($image);
            $this->em->persist($source);

            // Make it stick.
            $this->em->flush();

            // Create queue message.
            $processMessage = new ProcessMessage();
            $processMessage->setOperation(VendorState::INSERT)
                ->setIdentifierType($type)
                ->setIdentifier($identifier)
                ->setVendorId($this->getVendorId())
                ->setImageId($image->getId());

            // Send message into queue system into the search part.
            $this->producer->sendEvent('SearchTopic', JSON::encode($processMessage));

            // Update UI with progress information.
            ++$inserted;
            $this->progressMessageFormatted(0, $inserted, $inserted);
            $this->progressAdvance();
        }

        $this->progressFinish();

        $count = count($items);

        return VendorImportResultMessage::success($count, 0, $count, 0);
    }

    /**
     * Get filename from item id.
     *
     * @param string $id
     *
     * @return mixed
     */
    private function extractFilename(string $id): string
    {
        $parts = explode('/', $id);

        return array_pop($parts);
    }

    /**
     * Try to figure out the identifier from the filename.
     *
     * NOTE: that we assumes right now that the filename is the identifier urlencoded.
     *
     * @param string $filename
     *   The filename
     *
     * @return string
     *   The identifier found
     */
    private function filenameToIdentifier(string $filename): string
    {
        $filename = urldecode($filename);
        if (false !== strpos($filename, '.')) {
            $filename = explode('.', $filename);
            $filename = array_shift($filename);
        }

        return $filename;
    }

    /**
     * Try to find the type of the identifier.
     *
     * Default to ISBN if PID type is not found.
     *
     * @param string $identifier
     *   The identifier
     *
     * @return string
     *   The type detected
     */
    private function identifierToType(string $identifier): string
    {
        $type = IdentifierType::ISBN;

        if (false !== strpos($identifier, ':')) {
            $type = IdentifierType::PID;
        }

        return $type;
    }
}
