<?php
/**
 * @file
 * Service for updating magazine covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\DataWell\SearchService;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\OverDrive\Api\Client;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class OverDriveMagazinesVendorService.
 */
class OverDriveMagazinesVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 16;

    private const VENDOR_SEARCH_TERM = 'facet.acSource="ereolen magazines"';
    private const VENDOR_MAGAZINE_URL_BASE = 'http://link.overdrive.com/';

    private $searchService;
    private $httpClient;
    private $apiClient;

    /**
     * OverDriveMagazinesVendorService constructor.
     *
     * @param eventDispatcherInterface $eventDispatcher
     *   Dispatcher to trigger async jobs on import
     * @param entityManagerInterface $entityManager
     *   Doctrine entity manager
     * @param loggerInterface $statsLogger
     *   Logger object to send stats to ES
     * @param SearchService $searchService
     *   Datawell search service
     * @param ClientInterface $httpClient
     *   Http client to send api requests
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $statsLogger, SearchService $searchService, ClientInterface $httpClient, Client $apiClient)
    {
        parent::__construct($eventDispatcher, $entityManager, $statsLogger);

        $this->searchService = $searchService;
        $this->httpClient = $httpClient;
        $this->apiClient = $apiClient;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        $this->loadConfig();

        $this->progressStart('Search data well for: "'.self::VENDOR_SEARCH_TERM.'"');

        $offset = 1;
        try {
            do {
                $this->progressMessage('Search data well for: "'.self::VENDOR_SEARCH_TERM.'" (Offset: '.$offset.')');

                // Search the data well for material with acSource set to "ereolen magazines".
                [$pidArray, $more, $offset] = $this->searchService->search(self::VENDOR_SEARCH_TERM, $offset);

                // Get the OverDrive APIs title urls from the results
                $pidArray = $this->getTitleUrls($pidArray);

                // Get the OverDrive APIs crossRefIds from the results
                $pidArray = $this->getTitleIds($pidArray);

                // Get the OverDrive cover urls
                $pidArray = $this->getCoverUrls($pidArray);

                $batchSize = \count($pidArray);
                $this->updateOrInsertMaterials($pidArray, IdentifierType::PID, $batchSize);

                $this->progressMessageFormatted($this->totalUpdated, $this->totalInserted, $this->totalIsIdentifiers);
                $this->progressAdvance();

                if ($this->limit && $this->totalIsIdentifiers >= $this->limit) {
                    $more = false;
                }
            } while ($more);

            return VendorImportResultMessage::success($this->totalIsIdentifiers, $this->totalUpdated, $this->totalInserted, $this->totalDeleted);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    private function loadConfig(): void
    {
        $libraryAccountEndpoint = $this->getVendor()->getDataServerURI();
        $clientId = $this->getVendor()->getDataServerUser();
        $clientSecret = $this->getVendor()->getDataServerPassword();

        $this->apiClient->setCredentials($libraryAccountEndpoint, $clientId, $clientSecret);
    }

    /**
     * Get the title urls for all results in array.
     *
     * @param array $pidArray
     *   An array of pid => result to be converted
     *
     * @return array
     *   Array of pid => title url
     */
    private function getTitleUrls(array $pidArray): array
    {
        $result = [];
        foreach ($pidArray as $pid => $item) {
            $result[$pid] = $this->getTitleUrlFromResult($item['record']['identifier']);
        }

        return $result;
    }

    /**
     * Get the OverDrive title id for all results in array.
     *
     * @param array $pidArray
     *   An array of pid => title url to be converted
     *
     * @return array
     *   Array of pid => title id
     */
    private function getTitleIds(array $pidArray): array
    {
        $result = [];
        foreach ($pidArray as $pid => $titleUrl) {
            $result[$pid] = $this->getTitleIdFromUrl($titleUrl);
        }

        return $result;
    }

    /**
     * Get the image urls for all results in array.
     *
     * @param array $pidArray
     *   An array of pid => title id to be converted
     *
     * @return array
     *   Array of pid => cover url
     */
    private function getCoverUrls(array $pidArray): array
    {
        $result = [];
        foreach ($pidArray as $pid => $crossRefID) {
            $result[$pid] = $this->apiClient->getCoverUrl($crossRefID);
        }

        return $result;
    }

    /**
     * Get the OverDrive title urls from the data well result.
     *
     * @param array $result
     *   A data well result array
     *
     * @return string|null
     *   Title url or null
     */
    private function getTitleUrlFromResult(array $result): ?string
    {
        foreach ($result as $item) {
            $pos = strpos($item['$'], self::VENDOR_MAGAZINE_URL_BASE);
            if (false !== $pos) {
                return $item['$'];
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
     * @return string|null
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
}
