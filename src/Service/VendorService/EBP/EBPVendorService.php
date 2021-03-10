<?php
/**
 * @file
 * Service for updating data from 'EBP' tsv file.
 */

namespace App\Service\VendorService\EBP;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\VendorServiceInterface;

/**
 * Class EBPVendorService.
 */
class EBPVendorService extends AbstractTsvVendorService implements VendorServiceInterface
{
    protected const VENDOR_ID = 13;

    protected $vendorArchiveDir = 'EBP';
    protected $vendorArchiveName = 'ebp.load.tsv';
}
