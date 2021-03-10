<?php
/**
 * @file
 * Service for updating data from 'boardgamegeek' tsv file.
 */

namespace App\Service\VendorService\Boardgamegeek;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\VendorServiceInterface;

/**
 * Class BoardgamegeekVendorService.
 */
class BoardgamegeekVendorService extends AbstractTsvVendorService implements VendorServiceInterface
{
    protected const VENDOR_ID = 11;

    protected $vendorArchiveDir = 'Boardgamegeek';
    protected $vendorArchiveName = 'boardgamegeek.load.tsv';
}
