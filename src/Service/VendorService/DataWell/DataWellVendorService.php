<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\DataWell;

use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;

/**
 * Class DataWellVendorService.
 *
 * @deprecated deprecated since version 3.2.0
 */
class DataWellVendorService implements VendorServiceInterface
{
    use VendorServiceTrait;

    public const VENDOR_ID = 4;
}
