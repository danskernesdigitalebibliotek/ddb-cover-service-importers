<?php
/**
 * @file
 * Service for updating data from 'Musicbrainz' tsv file.
 */

namespace App\Service\VendorService\MusicBrainz;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\VendorServiceInterface;

/**
 * Class MusicBrainzVendorService.
 */
class MusicBrainzVendorService extends AbstractTsvVendorService implements VendorServiceInterface
{
    protected const VENDOR_ID = 9;

    protected $vendorArchiveDir = 'MusicBrainz';
    protected $vendorArchiveName = 'mb.covers.tsv';
}
