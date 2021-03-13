<?php
/**
 * @file
 * End user upload images.
 */

namespace App\Service\VendorService\UserUpload;

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
}
