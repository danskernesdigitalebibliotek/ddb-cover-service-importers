<?php
/**
 * @file
 * Service for updating data from 'Chandos' tsv file.
 */

namespace App\Service\VendorService\Chandos;

use App\Service\VendorService\AbstractTsvVendorService;

/**
 * Class ChandosVendorService.
 */
class ChandosVendorService extends AbstractTsvVendorService
{
    protected const VENDOR_ID = 10;

    protected $vendorArchiveDir = 'Chandos';
    protected $vendorArchiveName = 'chandos.load.tsv';
}