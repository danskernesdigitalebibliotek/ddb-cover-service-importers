<?php
/**
 * @file
 * Service for updating magazine covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Exception\UninitializedPropertyException;
use App\Exception\UnknownVendorServiceException;
use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\OverDrive\Api\Client;
use App\Service\VendorService\OverDrive\Api\Exception\AccountException;
use App\Service\VendorService\OverDrive\Api\Exception\AuthException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

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
    protected function extractData(object $jsonContent): array
    {
        $pidArray = $this->datawell->extractData($jsonContent);

        // Get the OverDrive APIs title urls from the results
        $pidTitleUrlArray = array_map('self::getTitleUrlFromDatawellIdentifiers', $pidArray);
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
     * @param object $result
     *   A data well result array
     *
     * @return string|null
     *   Title url or null
     */
    private function getTitleUrlFromDatawellIdentifiers(object $result): ?string
    {
        $identifiers = $result->record->identifier;

        // Loop through identifiers to look for urls starting with 'http://link.overdrive.com/'
        // E.g. http://link.overdrive.com/?websiteID=100515&titleID=5849553
        foreach ($identifiers as $identifier) {
            $pos = strpos((string) $identifier->{'$'}, self::VENDOR_MAGAZINE_URL_BASE);
            if (false !== $pos) {
                return $identifier->{'$'};
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
     * @return string|null
     *
     * @throws AccountException
     * @throws AuthException
     * @throws InvalidArgumentException
     * @throws IdentityProviderException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function getCoverUrl(string $crossRefID): ?string
    {
        return $this->apiClient->getCoverUrl($crossRefID);
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     */
    protected function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        $libraryAccountEndpoint = $vendor->getDataServerURI();
        $clientId = $vendor->getDataServerUser();
        $clientSecret = $vendor->getDataServerPassword();

        if (null === $libraryAccountEndpoint || null === $clientId || null === $clientSecret) {
            throw new UninitializedPropertyException('Incomplete config for '.self::class);
        }

        $this->apiClient->setLibraryAccountEndpoint($libraryAccountEndpoint);
        $this->apiClient->setCredentials($clientId, $clientSecret);
    }
}
