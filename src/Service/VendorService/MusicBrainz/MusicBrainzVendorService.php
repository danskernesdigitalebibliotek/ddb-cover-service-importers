<?php
/**
 * @file
 * Service for updating data from 'Musicbrainz' tsv file.
 */

namespace App\Service\VendorService\MusicBrainz;

use App\Service\VendorService\AbstractTsvVendorService;

/**
 * Class MusicBrainzVendorService.
 */
class MusicBrainzVendorService extends AbstractTsvVendorService
{
    public const VENDOR_ID = 9;

    protected string $vendorArchiveDir = 'MusicBrainz';
    protected string $vendorArchiveName = 'mb.covers.tsv';
}
