<?php
/**
 * @file
 * Abstract base class for the vendor services.
 */

namespace App\Service\VendorService;

use App\Entity\Vendor;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Repository\SourceRepository;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\Query\QueryException;

/**
 * Class AbstractBaseVendorService.
 *
 * This class is basically an wrapper class for CoreVendorService to ensure that shared dependencies is not required to
 * be changed in every vendor in the constructors of the child classes when they change in CoreVendorService.
 *
 * For the implementation details see the CoreVendorService class.
 *
 * @see VendorCoreService
 */
abstract class AbstractBaseVendorService
{
    protected const VENDOR_ID = 0;

    protected const BATCH_SIZE = 200;
    protected const ERROR_RUNNING = 'Import already running';

    protected $limit = 0;

    private $coreVendorService;

    /**
     * AbstractBaseVendorService constructor.
     *
     * @param VendorCoreService $vendorCoreService
     */
    public function __construct(VendorCoreService $vendorCoreService)
    {
        $this->coreVendorService = $vendorCoreService;
    }

    /**
     * Load new data from vendor.
     *
     * @return VendorImportResultMessage
     */
    abstract public function load(): VendorImportResultMessage;

    /**
     * Set dispatch to queue.
     *
     * @param bool $dispatchToQueue
     *   If true send events into queue system (default: true)
     */
    final public function setDispatchToQueue(bool $dispatchToQueue): void
    {
        $this->coreVendorService->setDispatchToQueue($dispatchToQueue);
    }

    /**
     * Set with updates.
     *
     * @param bool $withUpdates
     *   If true existing covers are updated (default: false)
     */
    final public function setWithUpdates(bool $withUpdates): void
    {
        $this->coreVendorService->setWithUpdates($withUpdates);
    }

    /**
     * Set the amount of records imported per vendor.
     *
     * @param int $limit
     *   The limit to use (default: 0 - no limit)
     */
    final public function setLimit(int $limit): void
    {
        $this->limit = $limit;
        $this->coreVendorService->setLimit($limit);
    }

    /**
     * Get the database id of the vendor the class represents.
     *
     * @return int
     *
     * @throws IllegalVendorServiceException
     */
    final public function getVendorId(): int
    {
        return $this->coreVendorService->getVendorId();
    }

    /**
     * Get the name of the vendor.
     *
     * @return string
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    final public function getVendorName(): string
    {
        return $this->coreVendorService->getVendorName();
    }

    /**
     * Get the Vendor object.
     *
     * @return Vendor
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    final public function getVendor(): Vendor
    {
        return $this->coreVendorService->getVendor();
    }

    /**
     * Acquire service lock to ensure we don't run multiple imports for the
     * same vendor in parallel.
     *
     * @return bool
     *
     * @throws IllegalVendorServiceException
     */
    protected function acquireLock(): bool
    {
        return $this->coreVendorService->acquireLock();
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
     *   The number of records to flush to the database pr. batch
     *
     * @throws \Exception
     */
    protected function updateOrInsertMaterials(VendorStatus $status, array &$identifierImageUrlArray, string $identifierType, int $batchSize = self::BATCH_SIZE): void
    {
        $this->coreVendorService->updateOrInsertMaterials($status, $identifierImageUrlArray, $identifierType, $batchSize);
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
    protected function processBatch(array $batch, SourceRepository $sourceRepo, string $identifierType): array
    {
        return $this->coreVendorService->processBatch($batch, $sourceRepo, $identifierType);
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
    protected function deleteRemovedMaterials(array &$identifierArray): int
    {
        return $this->coreVendorService->deleteRemovedMaterials($identifierArray);
    }
}
