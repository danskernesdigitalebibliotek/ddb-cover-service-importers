<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\DataWell;

use App\Service\VendorService\AbstractDataWellVendorService;

/**
 * Class ComicsPlusVendorService.
 */
class BlockBusterVendorService extends AbstractDataWellVendorService
{
    protected const VENDOR_ID = 20;
    protected const DATAWELL_URL_RELATION = 'dbcaddi:hasImage';

    protected array $datawellQuery = ['term.acSource=Libry films'];

    /**
     * {@inheritdoc}
     */
    protected function extractData(array $jsonContent): array
    {
        return $this->datawell->extractCoverUrl($jsonContent, self::DATAWELL_URL_RELATION);
    }
}
