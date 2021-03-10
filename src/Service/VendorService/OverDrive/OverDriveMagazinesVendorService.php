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
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $statsLogger, SearchService $searchService, Client $apiClient)
    {
        parent::__construct($eventDispatcher, $entityManager, $statsLogger);

        $this->searchService = $searchService;
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
                [$pidResultArray, $more, $offset] = $this->searchService->search(self::VENDOR_SEARCH_TERM, $offset);

                // Get the OverDrive APIs title urls from the results
                $pidTitleUrlArray = array_map('self::getTitleUrlFromDatableIdentifiers', $pidResultArray);

                // Get the OverDrive APIs crossRefIds from the results
                $pidTitleIdArray = array_map('self::getTitleIdFromUrl', $pidTitleUrlArray);

                // Get the OverDrive cover urls
                $pidCoverUrlArray = array_map('self::getCoverUrl', $pidTitleIdArray);

                // Remove null values
                array_filter($pidCoverUrlArray);

                $batchSize = \count($pidCoverUrlArray);
                $this->updateOrInsertMaterials($pidCoverUrlArray, IdentifierType::PID, $batchSize);

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
        $this->apiClient->setLibraryAccountEndpoint($libraryAccountEndpoint);

        $clientId = $this->getVendor()->getDataServerUser();
        $clientSecret = $this->getVendor()->getDataServerPassword();
        $this->apiClient->setCredentials($clientId, $clientSecret);
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
    private function getTitleUrlFromDatableIdentifiers(array $result): ?string
    {
        $identifiers = $result['record']['identifier'];

        // Loop through identifiers to look for urls starting with 'http://link.overdrive.com/'
        // E.g. http://link.overdrive.com/?websiteID=100515&titleID=5849553
        foreach ($identifiers as $identifier) {
            $pos = strpos($identifier['$'], self::VENDOR_MAGAZINE_URL_BASE);
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

    /**
     * Get cover url from OverDrive crossRefId.
     *
     * @param string $crossRefID
     *   The OverDrive crossRefID
     *
     * @return string|null
     *   The cover url or null
     *
     * @throws Api\Exception\AuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getCoverUrl(string $crossRefID): ?string
    {
        return $this->apiClient->getCoverUrl($crossRefID);
    }
}
