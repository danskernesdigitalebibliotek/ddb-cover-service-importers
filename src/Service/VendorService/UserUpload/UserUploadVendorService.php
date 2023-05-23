<?php
/**
 * @file
 * End user upload images.
 */

namespace App\Service\VendorService\UserUpload;

use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;

/**
 * Class UserUploadVendorService.
 */
class UserUploadVendorService implements VendorServiceInterface
{
    use VendorServiceTrait;

    public const VENDOR_ID = 15;
}
