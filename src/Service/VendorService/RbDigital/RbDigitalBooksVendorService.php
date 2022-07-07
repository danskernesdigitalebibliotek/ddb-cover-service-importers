<?php
/**
 * @file
 * Service for updating book covers from 'RB Digital'.
 */

namespace App\Service\VendorService\RbDigital;

use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class RbDigitalBooksVendorService.
 *
 * @deprecated deprecated since version 3.0.0
 */
class RbDigitalBooksVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 7;

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        return VendorImportResultMessage::error('Vendor deprecated');
    }
}
