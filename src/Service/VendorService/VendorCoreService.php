<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\UnknownVendorServiceException;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Service\MetricsService;
use App\Utils\Types\VendorState;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\QueryException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoreVendorService.
 */
final class VendorCoreService
{
    private $em;
    private $metricsService;
    private $bus;
    private $lockFactory;

    private $vendors = [];
    private $locks = [];

    /**
     * CoreVendorService constructor.
     *
     * @param entityManagerInterface $entityManager
     *   Doctrine entity manager
     * @param messageBusInterface $bus
     *   Job queue bus
     * @param metricsService $metricsService
     *   Metrics collection service
     * @param LockFactory $vendorLockFactory
     *   Vendor lock-factory used to prevent more that one instance of import at one time
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, MetricsService $metricsService, LockFactory $vendorLockFactory)
    {
        $this->em = $entityManager;
        $this->metricsService = $metricsService;
        $this->bus = $bus;
        $this->lockFactory = $vendorLockFactory;
    }

    /**
     * Get the name of the vendor.
     *
     * @param int $vendorId
     *   The identifier for the vendor
     *
     * @return string
     *
     * @throws UnknownVendorServiceException
     */
    public function getVendorName(int $vendorId): string
    {
        return $this->getVendor($vendorId)->getName();
    }

    /**
     * Get the Vendor object.
     *
     * @param int $vendorId
     *   The identifier for the vendor
     *
     * @return Vendor
     *   The vendor found
     *
     * @throws UnknownVendorServiceException
     */
    public function getVendor(int $vendorId): Vendor
    {
        // If a subclass has cleared all from the entity manager we reload the
        // vendor from the DB.
        if (!isset($this->vendors[$vendorId]) || !$this->em->contains($this->vendors[$vendorId])) {
            $vendorRepos = $this->em->getRepository(Vendor::class);
            $this->vendors[$vendorId] = $vendorRepos->findOneById($vendorId);
        }

        if (!$this->vendors[$vendorId] || empty($this->vendors[$vendorId])) {
            throw new UnknownVendorServiceException('Vendor with ID: '.$vendorId.' not found in DB');
        }

        return $this->vendors[$vendorId];
    }

    /**
     * Acquire service lock to ensure we don't run multiple imports for the
     * same vendor in parallel.
     *
     * @param int $vendorId
     *   Using the vendor ID to identify the lock
     * @param bool $ingnore
     *   Ignore the lock if not acquired
     *
     * @return bool
     *   Whether or not the lock had been acquired
     */
    public function acquireLock(int $vendorId, bool $ingnore = false): bool
    {
        $this->locks[$vendorId] = $this->lockFactory->createLock('app-vendor-service-load-'.$vendorId, 1800, false);
        $acquired = $this->locks[$vendorId]->acquire();

        return $ingnore ? true : $acquired;
    }

    /**
     * Release lock.
     *
     * @param int $vendorId
     *   The vendor ID to release
     */
    public function releaseLock(int $vendorId)
    {
        if (isset($this->locks[$vendorId])) {
            $this->locks[$vendorId]->release();
        }
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
     * @param int $vendorId
     *   The vendor id of the vendor to process
     * @param bool $withUpdates
     *   Process updates (default: false)
     * @param bool $withoutQueue
     *   Process without add jobs to queue system
     * @param int $batchSize
     *   The number of records to flush to the database pr. batch.
     *
     * @TODO: Split into update and insert function. One function one job.
     *
     * @throws QueryException
     * @throws UnknownVendorServiceException
     */
    public function updateOrInsertMaterials(VendorStatus $status, array &$identifierImageUrlArray, string $identifierType, int $vendorId, bool $withUpdates = false, bool $withoutQueue = false, int $batchSize = 200): void
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
            $batch = \array_slice($identifierImageUrlArray, $offset, $batchSize, true);
            [$updatedIdentifiers, $insertedIdentifiers] = $this->processBatch($batch, $sourceRepo, $identifierType, $vendorId, $withUpdates);

            // Send event with the last batch to the job processors.
            if ($withoutQueue) {
                $this->createVendorImageMessage($updatedIdentifiers, $insertedIdentifiers, $identifierType, $vendorId, $withUpdates);
            }

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
     * @param int $vendorId
     *   The Id of the vendor to process batch for
     * @param bool $withUpdates
     *   Process updates (default: false)
     *
     * @return array
     *   Array containing two arrays with identifiers for updated and inserted sources
     *
     * @throws QueryException
     * @throws UnknownVendorServiceException
     */
    public function processBatch(array $batch, SourceRepository $sourceRepo, string $identifierType, int $vendorId, bool $withUpdates): array
    {
        // Split into to results arrays (updated and inserted).
        $updatedIdentifiers = [];
        $insertedIdentifiers = [];

        $vendor = $this->getVendor($vendorId);

        // Load batch from database to enable updates.
        $sources = $sourceRepo->findByMatchIdList($identifierType, $batch, $vendor);

        foreach ($batch as $identifier => $imageUrl) {
            if (array_key_exists($identifier, $sources)) {
                if ($withUpdates) {
                    /* @var Source $source */
                    $source = $sources[$identifier];
                    $source->setMatchType($identifierType)
                        ->setMatchId($identifier)
                        ->setVendor($vendor)
                        ->setDate(new \DateTime())
                        ->setOriginalFile($imageUrl);
                    $updatedIdentifiers[] = $identifier;
                }
            } else {
                $source = new Source();
                $source->setMatchType($identifierType)
                    ->setMatchId($identifier)
                    ->setVendor($vendor)
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
     * Send VendorImageMessages into job queue with identifiers to process.
     *
     * @param array $updatedIdentifiers
     *   Updated identifiers
     * @param array $insertedIdentifiers
     *   Inserted identifiers
     * @param string $identifierType
     *   The type of identifiers in to be processed
     * @param int $vendorId
     *   The vendor's ID that are sending jobs into queue
     * @param bool $withUpdates
     *   If true existing covers will be updated
     */
    public function createVendorImageMessage(array $updatedIdentifiers, array $insertedIdentifiers, string $identifierType, int $vendorId, bool $withUpdates = false): void
    {
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
        if (!empty($updatedIdentifiers) && $withUpdates) {
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
