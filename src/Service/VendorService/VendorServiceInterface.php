<?php

namespace App\Service\VendorService;

use App\Utils\Message\VendorImportResultMessage;

/**
 * Interface VendorServiceInterface.
 *
 * All vendors should implement this interface to ensure they are discovered by the system.
 */
interface VendorServiceInterface
{
    /**
     * Loading data from the vendor for processing.
     *
     * @return VendorImportResultMessage
     *  To hold vendor import success information
     */
    public function load(): VendorImportResultMessage;
}
