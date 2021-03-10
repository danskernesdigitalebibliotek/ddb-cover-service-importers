<?php
/**
 * @file
 * End user upload images.
 */

namespace App\Service\VendorService\UserUpload;

use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\VendorServiceInterface;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class UserUploadVendorService.
 */
class UserUploadVendorService extends AbstractBaseVendorService implements VendorServiceInterface
{
    protected const VENDOR_ID = 15;

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        throw new \RuntimeException('This vendor is not runnable');
    }
}
