<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\BlockBuster;

use App\Service\VendorService\AbstractDataWellVendorService;

/**
 * Class ComicsPlusVendorService.
 */
class BlockBusterVendorService extends AbstractDataWellVendorService
{
    public const VENDOR_ID = 21;
    protected const DATAWELL_URL_RELATION = 'dbcaddi:hasImage';

    protected array $datawellQueries = ['term.acSource="Bibliotekernes filmtjeneste"'];

    /**
     * {@inheritdoc}
     */
    protected function extractData(object $jsonContent): array
    {
        return $this->datawell->extractCoverUrl($jsonContent, self::DATAWELL_URL_RELATION);
    }
}
