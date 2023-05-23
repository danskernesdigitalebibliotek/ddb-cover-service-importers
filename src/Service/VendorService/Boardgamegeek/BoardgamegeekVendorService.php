<?php
/**
 * @file
 * Service for updating data from 'boardgamegeek' tsv file.
 */

namespace App\Service\VendorService\Boardgamegeek;

use App\Service\VendorService\AbstractTsvVendorService;

/**
 * Class BoardgamegeekVendorService.
 */
class BoardgamegeekVendorService extends AbstractTsvVendorService
{
    public const VENDOR_ID = 11;

    protected string $vendorArchiveDir = 'BoardGameGeek';
    protected string $vendorArchiveName = 'boardgamegeek.load.tsv';
}
