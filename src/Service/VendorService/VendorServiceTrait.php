<?php
/**
 * @file
 * Trait adding set shared configuration functions.
 */

namespace App\Service\VendorService;

/**
 * Trait VendorServiceTrait.
 */
trait VendorServiceTrait
{
    private $limit = 0;
    private $withoutQueue = false;
    private $withUpdates = false;
    private $ignoreLock = false;
    private $vendorCoreService;

    /**
     * {@inheritdoc}
     */
    public function setVendorCoreService(VendorCoreService $vendorCoreService): void
    {
        $this->vendorCoreService = $vendorCoreService;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorId(): int
    {
        return $this::VENDOR_ID;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorName(): string
    {
        return $this->vendorCoreService->getVendorName($this->getVendorId());
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function setLimit(int $limit = 0)
    {
        $this->limit = $limit;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function setWithoutQueue(bool $withoutQueue = false)
    {
        $this->withoutQueue = $withoutQueue;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function setWithUpdates(bool $withUpdates = false)
    {
        $this->withUpdates = $withUpdates;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function setIgnoreLock(bool $force = false)
    {
        $this->ignoreLock = $force;
    }
}
