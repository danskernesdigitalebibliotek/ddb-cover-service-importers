<?php

/**
 * @file
 * Get cover from PressReader based on data well searches.
 */

namespace App\Service\VendorService\PressReader;

use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\VendorImageValidatorService;

/**
 * Class PressReaderVendorService.
 */
class PressReaderVendorService extends AbstractDataWellVendorService
{
    public const VENDOR_ID = 19;
    private const URL_PATTERN = 'https://i.prcdn.co/img?cid=%s&page=1&width=1200';
    private const MIN_IMAGE_SIZE = 40000;

    protected array $datawellQueries = ['facet.acSource="pressreader"'];

    /**
     * DataWellVendorService constructor.
     *
     * @param DataWellClient $datawell
     *   For searching the data well
     * @param VendorImageValidatorService $imageValidatorService
     *   Image validator
     */
    public function __construct(
        protected readonly DataWellClient $datawell,
        private readonly VendorImageValidatorService $imageValidatorService
    ) {
    }

    /**
     * {@inheritdoc}
     */
    protected function extractData(object $jsonContent): array
    {
        $pidArray = $this->datawell->extractData($jsonContent);
        $this->transformUrls($pidArray);

        return $pidArray;
    }

    /**
     * Transform/substitute the URL from the datawell to CDN https://i.prcdn.co/img?cid={$id}&page=1&width=1200.
     *
     * @param array $pidArray
     *   The array of PIDs indexed by pid containing URLs
     */
    private function transformUrls(array &$pidArray): void
    {
        foreach ($pidArray as $pid => &$url) {
            [$agency, $id] = explode(':', $pid);
            $url = sprintf($this::URL_PATTERN, $id);
        }
    }
}
