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
    protected const VENDOR_ID = 19;
    private const URL_PATTERN = 'https://i.prcdn.co/img?cid=%s&page=1&width=1200';
    private const MIN_IMAGE_SIZE = 40000;

    protected array $datawellQuery = ['facet.acSource="pressreader"'];

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
        parent::__construct($datawell);
    }

    /**
     * {@inheritdoc}
     */
    protected function extractData(array $jsonContent): array
    {
        $pidArray = $this->datawell->extractData($jsonContent);
        $this->transformUrls($pidArray);

        // The press reader CDN insert at special image saying that the content is not updated for newest news
        // cover. See https://i.prcdn.co/img?cid=9L09&page=1&width=1200, but the size will be under 40Kb, so we have
        // this extra test.
        return array_filter($pidArray, function ($url) {
            $headers = $this->imageValidatorService->remoteImageHeader('content-length', $url);
            if (!empty($headers)) {
                $header = reset($headers);
                if ($header < $this::MIN_IMAGE_SIZE) {
                    // Size to little set it to null.
                    return false;
                }
            } else {
                // Size header not found.
                return false;
            }

            return true;
        });
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
