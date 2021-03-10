<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Service\MetricsService;
use App\Utils\Types\VendorState;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\QueryException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoreVendorService.
 */
final class VendorCoreService
{
    protected const VENDOR_ID = 0;

    protected const BATCH_SIZE = 200;
    protected const ERROR_RUNNING = 'Import already running';

    private $vendor;

    protected $em;
    protected $dispatcher;
    protected $metricsService;
    protected $bus;

    protected $dispatchToQueue = true;
    protected $withUpdates = false;
    protected $limit = 0;

    /**
     * CoreVendorService constructor.
     *
     * @param entityManagerInterface $entityManager
     *   Doctrine entity manager
     * @param messageBusInterface $bus
     *   Job queue bus
     * @param metricsService $metricsService
     *   Metrics collection service
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, MetricsService $metricsService)
    {
        $this->em = $entityManager;
        $this->metricsService = $metricsService;
        $this->bus = $bus;
    }

    /**
     * Set dispatch to queue.
     *
     * @param bool $dispatchToQueue
     *   If true send events into queue system (default: true)
     */
    public function setDispatchToQueue(bool $dispatchToQueue)
    {
        $this->dispatchToQueue = $dispatchToQueue;
    }

    /**
     * Set with updates.
     *
     * @param bool $withUpdates
     *   If true existing covers are updated (default: false)
     */
    public function setWithUpdates(bool $withUpdates)
    {
        $this->withUpdates = $withUpdates;
    }

    /**
     * Set the amount of records imported per vendor.
     *
     * @param int $limit
     *   The limit to use (default: 0 - no limit)
     */
    public function setLimit(int $limit)
    {
        $this->limit = $limit;
    }

    /**
     * Get the database id of the vendor the class represents.
     *
     * @return int
     *
     * @throws IllegalVendorServiceException
     */
    public function getVendorId(): int
    {
        if (!is_int($this::VENDOR_ID) || $this::VENDOR_ID <= 0) {
            throw new IllegalVendorServiceException('VENDOR_ID must be a positive non-zero integer. Illegal value detected.');
        }

        return $this::VENDOR_ID;
    }

    /**
     * Get the name of the vendor.
     *
     * @return string
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    public function getVendorName(): string
    {
        return $this->getVendor()->getName();
    }

    /**
     * Get the Vendor object.
     *
     * @return Vendor
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    public function getVendor(): Vendor
    {
        // If a subclass has cleared all from the entity manager we reload the
        // vendor from the DB.
        if (!$this->vendor || !$this->em->contains($this->vendor)) {
            $vendorRepos = $this->em->getRepository(Vendor::class);
            $this->vendor = $vendorRepos->findOneById($this->getVendorId());
        }

        if (!$this->vendor || empty($this->vendor)) {
            throw new UnknownVendorServiceException('Vendor with ID: '.$this->getVendorId().' not found in DB');
        }

        return $this->vendor;
    }

    /**
     * Acquire service lock to ensure we don't run multiple imports for the
     * same vendor in parallel.
     *
     * @return bool
     *
     * @throws IllegalVendorServiceException
     */
    public function acquireLock(): bool
    {
        $store = new SemaphoreStore();
        $factory = new LockFactory($store);

        $lock = $factory->createLock('app-vendor-service-load-'.$this->getVendorId());

        return $lock->acquire();
    }

    /**
     * Update or insert source materials.
     *
     * @param VendorStatus $status
     *   The status counts for changes in inserts/update/delete
     * @param array $identifierImageUrlArray
     *   Array with identifier numbers => image URLs as key/value to update or insert
     * @param string $identifierType
     *   The type of identifier
     * @param int $batchSize
     *   The number of records to flush to the database pr. batch.
     *
     * @return VendorStatus
     *   The status counts for changes in inserts/update/delete
     *
     * @throws \Exception
     */
    public function updateOrInsertMaterials(VendorStatus $status, array &$identifierImageUrlArray, string $identifierType, int $batchSize = self::BATCH_SIZE): void
    {
        /** @var SourceRepository $sourceRepo */
        $sourceRepo = $this->em->getRepository(Source::class);

        $offset = 0;
        $count = \count($identifierImageUrlArray);
        $status->addRecords($count);

        while ($offset < $count) {
            // Update or insert in batches. Because doctrine lacks
            // 'INSERT ON DUPLICATE KEY UPDATE' we need to search for and load
            // sources already in the db.
            $batch = \array_slice($identifierImageUrlArray, $offset, self::BATCH_SIZE, true);
            [$updatedIdentifiers, $insertedIdentifiers] = $this->processBatch($batch, $sourceRepo, $identifierType);

            // Send event with the last batch to the job processors.
            $this->sendCoverImportEvents($updatedIdentifiers, $insertedIdentifiers, $identifierType);

            // Update status counts.
            $status->addInserted(count($insertedIdentifiers));
            $status->addUpdated(count($updatedIdentifiers));

            $offset += $batchSize;
        }
    }

    /**
     * Process one batch of identifiers.
     *
     * @param array $batch
     *   Array of identifiers to process
     * @param SourceRepository $sourceRepo
     *   Sources repository
     * @param string $identifierType
     *   The type of identifiers in to be processed
     *
     * @return array
     *   Array containing two arrays with identifiers for updated and inserted sources
     *
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     * @throws QueryException
     */
    public function processBatch(array $batch, SourceRepository $sourceRepo, string $identifierType): array
    {
        // Split into to results arrays (updated and inserted).
        $updatedIdentifiers = [];
        $insertedIdentifiers = [];

        // Load batch from database to enable updates.
        $sources = $sourceRepo->findByMatchIdList($identifierType, $batch, $this->getVendor());

        foreach ($batch as $identifier => $imageUrl) {
            if (array_key_exists($identifier, $sources)) {
                if ($this->withUpdates) {
                    /* @var Source $source */
                    $source = $sources[$identifier];
                    $source->setMatchType($identifierType)
                        ->setMatchId($identifier)
                        ->setVendor($this->vendor)
                        ->setDate(new \DateTime())
                        ->setOriginalFile($imageUrl);
                    $updatedIdentifiers[] = $identifier;
                }
            } else {
                $source = new Source();
                $source->setMatchType($identifierType)
                    ->setMatchId($identifier)
                    ->setVendor($this->vendor)
                    ->setDate(new \DateTime())
                    ->setOriginalFile($imageUrl);
                $this->em->persist($source);
                $insertedIdentifiers[] = $identifier;
            }
        }

        $this->em->flush();
        $this->em->clear();

        gc_collect_cycles();

        return [$updatedIdentifiers, $insertedIdentifiers];
    }

    /**
     * Delete all Source materials not found in latest import.
     *
     * @param array $identifierArray
     *   Array of found identification numbers
     *
     * @return int
     *   The number of source materials deleted
     */
    public function deleteRemovedMaterials(array &$identifierArray): int
    {
        // @TODO implement queueing jobs for DeleteProcessor
    }

    /**
     * Log statistics.
     */
    public function logStatistics(): void
    {
        $className = substr(\get_class($this), strrpos(\get_class($this), '\\') + 1);
    }

    /**
     * Send events to the job queue with identifiers to process.
     *
     * @param array $updatedIdentifiers
     *   Updated identifiers
     * @param array $insertedIdentifiers
     *   Inserted identifiers
     * @param string $identifierType
     *   The type of identifiers in to be processed
     */
    private function sendCoverImportEvents(array $updatedIdentifiers, array $insertedIdentifiers, string $identifierType): void
    {
        if ($this->dispatchToQueue) {
            $vendorId = $this->vendor->getId();

            if (!empty($insertedIdentifiers)) {
                foreach ($insertedIdentifiers as $identifier) {
                    $message = new VendorImageMessage();
                    $message->setOperation(VendorState::INSERT)
                        ->setIdentifier($identifier)
                        ->setVendorId($vendorId)
                        ->setIdentifierType($identifierType);
                    $this->bus->dispatch($message);
                }
            }
            if (!empty($updatedIdentifiers) && $this->withUpdates) {
                foreach ($updatedIdentifiers as $identifier) {
                    $message = new VendorImageMessage();
                    $message->setOperation(VendorState::UPDATE)
                        ->setIdentifier($identifier)
                        ->setVendorId($vendorId)
                        ->setIdentifierType($identifierType);
                    $this->bus->dispatch($message);
                }
            }

            // @TODO: DELETED event???
        }
    }
}
