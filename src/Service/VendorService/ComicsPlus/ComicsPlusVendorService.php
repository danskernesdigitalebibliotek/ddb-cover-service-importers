<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\DataWell;

use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\ComicsPlus\DataConverter\AmazonPublicUrlConverter;

/**
 * Class ComicsPlusVendorService.
 */
class ComicsPlusVendorService extends AbstractDataWellVendorService
{
    protected const VENDOR_ID = 21;
    protected const DATAWELL_URL_RELATION = 'dbcaddi:hasCover';

    protected array $datawellQuery = ['term.acSource=comics plus'];

    /**
     * {@inheritdoc}
     */
    protected function extractData(array $jsonContent): array
    {
        $pidArray = $this->datawell->extractCoverUrl($jsonContent, self::DATAWELL_URL_RELATION);

        // Convert images url from 'medium' to 'large'
        AmazonPublicUrlConverter::convertArrayValues($pidArray);

        return $pidArray;
    }
}