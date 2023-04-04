<?php
/**
 * @file
 * Service for updating book covers from 'RB Digital'.
 */

namespace App\Service\VendorService\RbDigital;

use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;

/**
 * Class RbDigitalBooksVendorService.
 *
 * @deprecated deprecated since version 3.0.0
 */
class RbDigitalBooksVendorService implements VendorServiceInterface
{
    use VendorServiceTrait;

    public const VENDOR_ID = 7;
}
