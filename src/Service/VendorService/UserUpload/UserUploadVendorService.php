<?php
/**
 * @file
 * End user upload images.
 */

namespace App\Service\VendorService\UserUpload;

use App\Entity\Vendor;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class UserUploadVendorService.
 */
class UserUploadVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 15;

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        throw new \RuntimeException('This vendor is not runnable');
    }

    /**
     * Get entity for this vendor.
     *
     *   Vendor entity
     *
     * @throws \App\Exception\UnknownVendorServiceException
     */
    public function getVendorEntity(): Vendor
    {
        return $this->vendorCoreService->getVendor(self::VENDOR_ID);
    }
}
