<?php
/**
 * @file
 * Service for updating magazine covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\OverDrive\Api\Client;
use Psr\Cache\InvalidArgumentException;

/**
 * Class OverDriveMagazinesVendorService.
 */
class OverDriveMagazinesVendorService extends AbstractDataWellVendorService
{
    protected const VENDOR_ID = 16;
    private const VENDOR_MAGAZINE_URL_BASE = 'link.overdrive.com';

    protected array $datawellQueries = ['facet.acSource="ereolen magazines"'];

    /**
     * OverDriveMagazinesVendorService constructor.
     *
     * @param DataWellClient $datawell
     *   Datawell search service
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(
        protected readonly DataWellClient $datawell,
        private readonly Client $apiClient
    ) {
    }

    /**
     * {@inheritdoc}
     */
    protected function extractData(array $jsonContent): array
    {
        $pidArray = $this->datawell->extractData($jsonContent);

        // Get the OverDrive APIs title urls from the results
        $pidTitleUrlArray = array_map('self::getTitleUrlFromDatableIdentifiers', $pidArray);
        $pidTitleUrlArray = array_filter($pidTitleUrlArray);

        // Get the OverDrive APIs crossRefIds from the results
        $pidTitleIdArray = array_map('self::getTitleIdFromUrl', $pidTitleUrlArray);
        $pidTitleIdArray = array_filter($pidTitleIdArray);

        // Get the OverDrive cover urls
        $pidCoverUrlArray = array_map('self::getCoverUrl', $pidTitleIdArray);

        return array_filter($pidCoverUrlArray);
    }

    /**
     * Get the OverDrive title urls from the data well result.
     *
     * @param array $result
     *   A data well result array
     *
     *   Title url or null
     */
    private function getTitleUrlFromDatableIdentifiers(array $result): ?string
    {
        $identifiers = $result['record']['identifier'];

        // Loop through identifiers to look for urls starting with 'http://link.overdrive.com/'
        // E.g. http://link.overdrive.com/?websiteID=100515&titleID=5849553
        foreach ($identifiers as $identifier) {
            $pos = strpos((string) $identifier['$'], self::VENDOR_MAGAZINE_URL_BASE);
            if (false !== $pos) {
                return $identifier['$'];
            }
        }

        return null;
    }

    /**
     * Get the OverDrive title id from the title url.
     *
     * @param string $url
     *   The OverDrive title url
     *
     *   The title id or null
     */
    private function getTitleIdFromUrl(string $url): ?string
    {
        // Example URL: http://link.overdrive.com/?websiteID=100515&titleID=5838146
        $urlQuery = parse_url($url, PHP_URL_QUERY);
        $result = [];
        parse_str($urlQuery, $result);

        return $result['titleID'] ?? null;
    }

    /**
     * Get cover url from OverDrive crossRefId.
     *
     * @param string $crossRefID
     *   The OverDrive crossRefID
     *
     *   The cover url or null
     *
     * @throws Api\Exception\AuthException
     * @throws InvalidArgumentException
     */
    private function getCoverUrl(string $crossRefID): ?string
    {
        return $this->apiClient->getCoverUrl($crossRefID);
    }
}
