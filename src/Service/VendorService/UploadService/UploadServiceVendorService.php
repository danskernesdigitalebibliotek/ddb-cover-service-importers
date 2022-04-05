<?php
/**
 * @file
 * Upload service to handle bulk uploaded images.
 */

namespace App\Service\VendorService\UploadService;

use App\Entity\Image;
use App\Entity\Source;
use App\Exception\CoverStoreAlreadyExistsException;
use App\Exception\CoverStoreCredentialException;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreInvalidResourceException;
use App\Exception\CoverStoreNotFoundException;
use App\Exception\CoverStoreTooLargeFileException;
use App\Exception\CoverStoreUnexpectedException;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class UploadServiceVendorService.
 */
class UploadServiceVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 12;

    protected const SOURCE_FOLDER = 'BulkUpload';
    protected const DESTINATION_FOLDER = 'UploadService';

    private CoverStoreInterface $store;
    private SourceRepository $sourceRepository;
    private MessageBusInterface $bus;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    /**
     * CoverStoreSearchCommand constructor.
     *
     * @param MessageBusInterface $bus
     *   Message queue bus
     * @param EntityManagerInterface $em
     *   Database entity manager
     * @param CoverStoreInterface $store
     *   Cover store access
     * @param SourceRepository $sourceRepository
     *   Source repository
     * @param LoggerInterface $informationLogger
     *   Logger to send importer status information
     */
    public function __construct(MessageBusInterface $bus, EntityManagerInterface $em, CoverStoreInterface $store, SourceRepository $sourceRepository, LoggerInterface $informationLogger)
    {
        $this->bus = $bus;
        $this->em = $em;
        $this->store = $store;
        $this->sourceRepository = $sourceRepository;
        $this->logger = $informationLogger;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \App\Exception\UnknownVendorServiceException
     */
    public function load(): VendorImportResultMessage
    {
        $status = new VendorStatus();
        $this->progressStart('Searching CoverStore BulkUpload folder for new images');

        $items = $this->store->search(self::SOURCE_FOLDER);

        // Labels for metrics services.
        $labels = [
            'type' => 'vendor',
            'vendorName' => $this->getVendorName(),
            'vendorId' => $this->getVendorId(),
        ];

        foreach ($items as $item) {
            $filename = $this->extractFilename($item->getId());
            if (!$this->isValidFilename($filename)) {
                $this->logger->info($this->getVendorName().' invalid filename', [
                    'service' => self::class,
                    'filename' => $filename,
                    'url' => $item->getUrl(),
                ]);
                $this->vendorCoreService->getMetricsService()->counter('vendor_invalid_filename_total', 'Invalid filename error', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            }

            // Get identifier from the image id.
            $identifier = $this->filenameToIdentifier($filename);
            $type = $this->identifierToType($identifier);

            try {
                $item = $this->store->move($item->getId(), self::DESTINATION_FOLDER.'/'.$identifier);
                $state = VendorState::INSERT;
                $this->logger->info($this->getVendorName().' image moved', [
                    'service' => self::class,
                    'type' => $type,
                    'identifier' => $identifier,
                    'url' => $item->getUrl(),
                ]);
            } catch (CoverStoreAlreadyExistsException $exception) {
                try {
                    // Update the image as it already exists.
                    $item = $this->store->move($item->getId(), self::DESTINATION_FOLDER.'/'.$identifier, true);
                    $state = VendorState::UPDATE;
                    $this->logger->info($this->getVendorName().' image updated', [
                        'service' => self::class,
                        'type' => $type,
                        'identifier' => $identifier,
                        'url' => $item->getUrl(),
                    ]);
                } catch (CoverStoreException $exception) {
                    $this->logger->error('Error moving image', [
                        'service' => self::class,
                        'message' => $exception->getMessage(),
                        'identifier' => $identifier,
                    ]);
                    $this->vendorCoreService->getMetricsService()->counter('vendor_moving_image_total', 'Moving image error', 1, $labels);
                    $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);

                    // The image may have been moved so we ignore this error an goes to the next item.
                    continue;
                }
            } catch (CoverStoreCredentialException $exception) {
                // Access issues.
                $this->logger->error('Access denied to cover store', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_access_denied_total', 'Cover store access denied', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            } catch (CoverStoreNotFoundException $exception) {
                // Log that the image did not exists.
                $this->logger->error('Cover store error - not found', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_not_found_total', 'Cover store not found', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            } catch (CoverStoreTooLargeFileException $exception) {
                $this->logger->error('Cover was to large', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_to_large_total', 'Cover store file size to large', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            } catch (CoverStoreUnexpectedException $exception) {
                $this->logger->error('Cover store unexpected error', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_unexpected_error_total', 'Cover store unexpected error', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            } catch (CoverStoreInvalidResourceException $exception) {
                $this->logger->error('Cover store invalid resource error', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_invalid_resource_total', 'Cover store invalid resource error', 1, $labels);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            } catch (CoverStoreException $exception) {
                $this->logger->error('Cover store error', [
                    'service' => self::class,
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                ]);
                $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                continue;
            }

            // Create new entity.
            $image = new Image();
            $source = new Source();

            if (VendorState::INSERT !== $state) {
                /** @var Source|null $source */
                $source = $this->sourceRepository->findOneBy([
                    'matchType' => $type,
                    'matchId' => $identifier,
                    'vendor' => $this->vendorCoreService->getVendor($this->getVendorId()),
                ]);
                if (!empty($source)) {
                    $image = $source->getImage();
                    if (!empty($image)) {
                        $this->logger->error($this->getVendorName().' error loading image', [
                            'service' => self::class,
                            'type' => $type,
                            'identifier' => $identifier,
                        ]);
                        $this->vendorCoreService->getMetricsService()->counter('coverstore_loading_image_total', 'Cover store total loading image', 1, $labels);
                        $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                        continue;
                    }
                    $this->vendorCoreService->getMetricsService()->counter('coverstore_loading_image_total', 'Cover store total loading image', 1, $labels);
                } else {
                    // Something un-expected happen here.
                    $this->logger->error($this->getVendorName().' error loading source', [
                        'service' => self::class,
                        'type' => $type,
                        'identifier' => $identifier,
                    ]);
                    $this->vendorCoreService->getMetricsService()->counter('coverstore_loading_source_total', 'Cover store total loading source', 1, $labels);
                    $this->vendorCoreService->getMetricsService()->counter('coverstore_error_total', 'Cover store error', 1, $labels);
                    continue;
                }
                $this->vendorCoreService->getMetricsService()->counter('coverstore_loading_source_total', 'Cover store total loading source', 1, $labels);
            }

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
                ->setVendor($this->vendorCoreService->getVendor($this->getVendorId()))
                ->setDate(new \DateTime())
                ->setOriginalFile($item->getUrl())
                ->setOriginalContentLength($item->getSize())
                ->setOriginalLastModified(new \DateTime())
                ->setImage($image);
            $this->em->persist($source);

            // Make it stick.
            $this->em->flush();

            // Create queue message.
            $message = new VendorImageMessage();
            $message->setOperation($state)
                ->setIdentifierType($type)
                ->setIdentifier($identifier)
                ->setVendorId($this->getVendorId())
                ->setImageId($image->getId());

            // Send message into queue system into the search part.
            $this->bus->dispatch($message);

            // Update UI with progress information.
            $status->addInserted(1);
            $status->addRecords(1);
            $this->progressMessageFormatted($status);
            $this->progressAdvance();
        }

        $this->progressFinish();

        return VendorImportResultMessage::success($status);
    }

    /**
     * Validate that the filename is an identifier.
     *
     * @param string $filename
     *   The filename to validate
     *
     * @return bool
     *   True if validated else false
     */
    private function isValidFilename(string $filename): bool
    {
        $identifier = $this->filenameToIdentifier($filename);

        // Basic test for isbn 10/13
        if (1 === preg_match('/^(\d{13}|\d{10})$/', $identifier, $matches)) {
            return true;
        }

        // Test for pid (eg. 870970-basis:47547946 or 150061-ebog:ODN0004246103).
        if (1 === preg_match('/^\d{6}-[a-zA-Z]+:[a-zA-Z0-9]+$/', $identifier, $matches)) {
            return true;
        }

        return false;
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

        // When dragging files into cover store UI it will transform '%' into '_'.
        if (false !== strpos($filename, '_')) {
            $filename = preg_replace('/(_3A)|(_)/', ':', $filename);
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
     * @return string The type detected
     *
     * @psalm-return 'isbn'|'pid'
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
