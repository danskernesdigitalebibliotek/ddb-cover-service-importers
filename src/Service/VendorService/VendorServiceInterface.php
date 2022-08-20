<?php

namespace App\Service\VendorService;

/**
 * Interface VendorServiceInterface.
 *
 * All vendors should implement this interface to ensure they are discovered by the system.
 */
interface VendorServiceInterface
{
    /**
     * Set the Vendor Core Service.
     *
     * @param VendorCoreService $vendorCoreService
     *   The Vendor core service
     */
    public function setVendorCoreService(VendorCoreService $vendorCoreService): void;

    /**
     * Get the vendor's unique ID.
     *
     * @return int
     *   The vendor's ID
     */
    public function getVendorId(): int;

    /**
     * Get the name of the vendor.
     *
     * @return string
     *   Name of the vendor
     */
    public function getVendorName(): string;
}
