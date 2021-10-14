<?php
/**
 * @file
 * Trait adding set shared configuration functions.
 */

namespace App\Service\VendorService;

use App\Exception\UnknownVendorServiceException;
use App\Utils\Types\VendorStatus;

/**
 * Trait VendorServiceTrait.
 */
trait VendorServiceTrait
{
    private int $limit = 0;
    private bool $withoutQueue = false;
    private \DateTime $withUpdatesDate;
    private bool $ignoreLock = false;
    private VendorCoreService $vendorCoreService;

    /**
     * Set core vendor service.
     *
     * Note: this function is called during object creation through services.yaml.
     *
     * @param VendorCoreService $vendorCoreService
     *   Service with shared functionality between vendors
     */
    public function setVendorCoreService(VendorCoreService $vendorCoreService): void
    {
        $this->vendorCoreService = $vendorCoreService;
    }

    /**
     * Log result of an vendor import.
     *
     * @param vendorStatus $status
     *   The vendor status object
     *
     * @throws UnknownVendorServiceException
     */
    public function logStatusMetrics(VendorStatus $status): void
    {
        $labels = [
          'type' => 'vendor',
          'vendorName' => $this->getVendorName(),
          'vendorId' => $this->getVendorId(),
        ];
        $this->vendorCoreService->getMetricsService()->counter('vendor_inserted_total', 'Number of inserted records', $status->inserted, $labels);
        $this->vendorCoreService->getMetricsService()->counter('vendor_updated_total', 'Number of updated records', $status->updated, $labels);
        $this->vendorCoreService->getMetricsService()->counter('vendor_deleted_total', 'Number of deleted records', $status->deleted, $labels);
        $this->vendorCoreService->getMetricsService()->counter('vendor_records_total', 'Number of records', $status->records, $labels);
    }

    /**
     * Get vendor id.
     *
     * @return int
     *   The ID of the current loaded vendor
     */
    public function getVendorId(): int
    {
        return $this::VENDOR_ID;
    }

    /**
     * Get name of the currently loaded vendor.
     *
     * @return string
     *   Vendor name
     *
     * @throws UnknownVendorServiceException
     */
    public function getVendorName(): string
    {
        return $this->vendorCoreService->getVendorName($this->getVendorId());
    }

    /**
     * Set vendor import limit.
     *
     * Mostly used for debugging vendor import issues.
     *
     * @param int $limit
     *   The limited amount of covers to process
     */
    public function setLimit(int $limit = 0): void
    {
        $this->limit = $limit;
    }

    /**
     * Disable/enable queue system.
     *
     * @param bool $withoutQueue
     *  If false messages is not sent into the queue system
     */
    public function setWithoutQueue(bool $withoutQueue = false): void
    {
        $this->withoutQueue = $withoutQueue;
    }

    /**
     * Update all vendor records during import.
     *
     * @param \DateTime $date
     *   Updated all records found after this date.
     */
    public function setWithUpdatesDate(\DateTime $date)
    {
        $this->withUpdatesDate = $date;
    }

    /**
     * Ignore resource locks.
     *
     * Mostly used to force vendor execution during debugging.
     *
     * @param bool $force
     *   If true locks are ignored
     */
    public function setIgnoreLock(bool $force = false)
    {
        $this->ignoreLock = $force;
    }
}
