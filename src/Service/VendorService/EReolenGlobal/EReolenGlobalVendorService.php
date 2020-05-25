<?php
/**
 * @file
 * Service for updating data from 'eReolen Global / overdrive.com''.
 */

namespace App\Service\VendorService\EReolenGlobal;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class EReolenGlobalVendorService.
 */
class EReolenGlobalVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 14;

    private const AUTH_URL = 'https://oauth.overdrive.com/token';
    private const AUTH_GRANT_TYPE = 'grant_type=client_credentials';

    private const ACCOUNT_API_URL = 'https://api.overdrive.com/v1/libraries/6247';

    private $httpClient;

    /**
     * EReolenGlobalVendorService constructor.
     *
     * @param EventDispatcherInterface $eventDispatcher
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     * @param ClientInterface $httpClient
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $statsLogger, ClientInterface $httpClient)
    {
        parent::__construct($eventDispatcher, $entityManager, $statsLogger);

        $this->httpClient = $httpClient;
    }

    /** {@inheritdoc} */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        try {
            $this->progressStart('Starting eReolen Global import from overdrive API');

            $productsUrl = $this->getProductsUrl();
            $totalCount = $this->limit ?? $this->getTotalItems($productsUrl);

            $batchSize = ($this->limit && $this->limit < self::BATCH_SIZE) ? $this->limit : self::BATCH_SIZE;
            $offset = 0;

            do {
                $products = $this->getProducts($productsUrl, $batchSize, $offset);

                $isbnImageUrlArray = [];
                foreach ($products as $product) {
                    $coverImageUrl = $product->images->cover->href ?? null;

                    foreach ($product->formats as $format) {
                        foreach ($format->identifiers as $identifier) {
                            if (IdentifierType::ISBN === strtolower($identifier->type)) {
                                $isbnImageUrlArray[$identifier->value] = $coverImageUrl;
                            }
                        }
                    }
                }

                $this->updateOrInsertMaterials($isbnImageUrlArray, IdentifierType::ISBN);

                $this->progressMessageFormatted($this->totalUpdated, $this->totalInserted, $this->totalIsIdentifiers);
                $this->progressAdvance();

                $offset += self::BATCH_SIZE;
            } while ($offset < $totalCount);

            $this->logStatistics();

            $this->progressFinish();

            return VendorImportResultMessage::success($this->totalIsIdentifiers, $this->totalUpdated, $this->totalInserted, $this->totalDeleted);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Get products from api.
     *
     * @param string $productsUrl
     * @param int $limit
     * @param int $offset
     *
     * @return array
     *
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     * @throws GuzzleException
     */
    private function getProducts(string $productsUrl, int $limit, int $offset): array
    {
        $response = $this->httpClient->request('GET', $productsUrl, [
            'headers' => [
                'User-Agent' => $this->getVendor()->getDataServerUser(),
                'Authorization' => 'Bearer '.$this->getAuthToken(),
            ],
            'query' => ['limit' => $limit, 'offset' => $offset],
        ]);

        $content = $response->getBody()->getContents();
        $content = json_decode($content);

        return $content->products;
    }

    /**
     * Get the total number of products from api.
     *
     * @param string $productsUrl
     *
     * @return int
     *
     * @throws GuzzleException
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     */
    private function getTotalItems(string $productsUrl): int
    {
        $response = $this->httpClient->request('GET', $productsUrl, [
            'headers' => [
                'User-Agent' => $this->getVendor()->getDataServerUser(),
                'Authorization' => 'Bearer '.$this->getAuthToken(),
            ],
        ]);

        $content = $response->getBody()->getContents();
        $content = json_decode($content);

        return $content->totalItems;
    }

    /**
     * Get Overdrive auth token.
     *
     * @see https://developer.overdrive.com/apis/client-auth
     *
     * @return string
     *
     * @throws GuzzleException
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     */
    private function getAuthToken(): string
    {
        $response = $this->httpClient->request('POST', self::AUTH_URL, [
                'auth' => [$this->getVendor()->getDataServerUser(), $this->getVendor()->getDataServerPassword()],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'],
                'body' => self::AUTH_GRANT_TYPE,
        ]);

        $content = $response->getBody()->getContents();
        $content = json_decode($content);

        return $content->access_token;
    }

    /**
     * Get the products url for the library user.
     *
     * @see https://developer.overdrive.com/docs/products-link
     *
     * @return string
     *
     * @throws GuzzleException
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     */
    private function getProductsUrl(): string
    {
        $response = $this->httpClient->request('GET', self::ACCOUNT_API_URL, [
            'headers' => [
                'User-Agent' => $this->getVendor()->getDataServerUser(),
                'Authorization' => 'Bearer '.$this->getAuthToken(),
            ],
        ]);

        $content = $response->getBody()->getContents();
        $content = json_decode($content);

        return $content->links->products->href;
    }
}
