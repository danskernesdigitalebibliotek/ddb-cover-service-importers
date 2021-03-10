<?php
/**
 * @file
 * Service for updating book covers from 'RB Digital'.
 */

namespace App\Service\VendorService\RbDigital;

use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class RbDigitalMagazinesVendorService.
 *
 * @deprecated deprecated since version 1.5.10
 */
class RbDigitalMagazinesVendorService extends AbstractBaseVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 8;

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        return VendorImportResultMessage::error('Vendor deprecated');
    }
}
