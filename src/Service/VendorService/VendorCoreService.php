<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\UnknownVendorServiceException;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Utils\Types\VendorState;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\QueryException;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoreVendorService.
 */
final class VendorCoreService
{
    private EntityManagerInterface $em;
    private MetricsService $metricsService;
    private MessageBusInterface $bus;
    private LockFactory $lockFactory;

    private array $vendors = [];
    private array $locks = [];

    // When the vendor load command is used with --with-updates-date or --days-ago a date is given back in time for
    // which we should look for covers that have not been indexed (no results found in the data well). Covers that have
    // not been mapped in the data well will only have "this limit value" in the search table and only these should be
    // re-index/re-search with the data well.
    private const UPDATE_COVER_LIMIT = 1;

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
     *   Vendor lock-factory used to prevent more than one instance of import at one time
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, MetricsService $metricsService, LockFactory $vendorLockFactory)
    {
        $this->em = $entityManager;
        $this->metricsService = $metricsService;
        $this->bus = $bus;
        $this->lockFactory = $vendorLockFactory;
    }

    /**
     * Get metrics service.
     *
     * @return metricsService
     *   The metrics service
     */
    public function getMetricsService(): MetricsService
    {
        return $this->metricsService;
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
     * @param bool $ignore
     *   Ignore the lock if not acquired
     *
     * @return bool
     *   Whether the lock had been acquired
     */
    public function acquireLock(int $vendorId, bool $ignore = false): bool
    {
        $this->locks[$vendorId] = $this->lockFactory->createLock('app-vendor-service-load-'.$vendorId, 1800, false);
        $acquired = $this->locks[$vendorId]->acquire();

        return $ignore ? true : $acquired;
    }

    /**
     * Release lock.
     *
     * @param int $vendorId
     *   The vendor ID to release
     *
     * @return void
     */
    public function releaseLock(int $vendorId): void
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
     * @param \DateTime $withUpdatesDate
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
    public function updateOrInsertMaterials(VendorStatus $status, array &$identifierImageUrlArray, string $identifierType, int $vendorId, \DateTime $withUpdatesDate, bool $withoutQueue = false, int $batchSize = 200): void
    {
        /** @var SourceRepository $sourceRepo */
        $sourceRepo = $this->em->getRepository(Source::class);

        $offset = 0;
        $inserted = 0;
        $updated = 0;
        $count = \count($identifierImageUrlArray);
        $status->addRecords($count);

        while ($offset < $count) {
            // Update or insert in batches. Because doctrine lacks
            // 'INSERT ON DUPLICATE KEY UPDATE' we need to search for and load
            // sources already in the db.
            $batch = \array_slice($identifierImageUrlArray, $offset, $batchSize, true);
            [$updatedIdentifiers, $insertedIdentifiers] = $this->processBatch($batch, $sourceRepo, $identifierType, $vendorId, $withUpdatesDate);

            // Send event with the last batch to the job processors.
            if ($withoutQueue) {
                $this->createVendorImageMessage($updatedIdentifiers, $insertedIdentifiers, $identifierType, $vendorId);
            }

            // Update status counts.
            $inserted += count($insertedIdentifiers);
            $updated += count($updatedIdentifiers);

            $offset += $batchSize;
        }

        // Log metrics here to en sure interrupted partial imports also get counted.
        $this->logStatusMetrics($vendorId, $count, $inserted, $updated);
        $status->addInserted($inserted);
        $status->addUpdated($updated);
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
     * @param \DateTime $withUpdatesDate
     *   Process updates (default: false)
     *
     * @return array (int|string)[][]
     *   Array containing two arrays with identifiers for updated and inserted sources
     *
     * @throws QueryException
     * @throws UnknownVendorServiceException
     */
    public function processBatch(array $batch, SourceRepository $sourceRepo, string $identifierType, int $vendorId, \DateTime $withUpdatesDate): array
    {
        // Split into to results arrays (updated and inserted).
        $updatedIdentifiers = [];
        $insertedIdentifiers = [];

        $vendor = $this->getVendor($vendorId);

        // Load batch from database to enable updates.
        $sources = $sourceRepo->findByMatchIdList($identifierType, $batch, $vendor);

        foreach ($batch as $identifier => $imageUrl) {
            if (array_key_exists($identifier, $sources)) {
                /* @var Source $source */
                $source = $sources[$identifier];
                if ($source->getDate() >= $withUpdatesDate && self::UPDATE_COVER_LIMIT === $source->getSearches()->count()) {
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

        return 0;
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
     */
    public function createVendorImageMessage(array $updatedIdentifiers, array $insertedIdentifiers, string $identifierType, int $vendorId): void
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
        if (!empty($updatedIdentifiers)) {
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

    /**
     * Log result of an vendor import.
     *
     * @param vendorStatus $status
     *   The vendor status object
     *
     * @throws UnknownVendorServiceException
     */
    private function logStatusMetrics(int $vendorId, int $count, int $inserted, int $updated): void
    {
        $vendor = $this->getVendor($vendorId);
        $labels = [
            'type' => 'vendor',
            'vendorName' => $vendor->getName(),
            'vendorId' => $vendor->getId(),
        ];
        $this->getMetricsService()->counter('vendor_inserted_total', 'Number of inserted records', $inserted, $labels);
        $this->getMetricsService()->counter('vendor_updated_total', 'Number of updated records', $updated, $labels);
        $this->getMetricsService()->counter('vendor_records_total', 'Number of records', $count, $labels);
    }
}
