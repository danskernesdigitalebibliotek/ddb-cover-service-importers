<?php

/**
 * @file
 * Service for updating book covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\OverDrive\Api\Client;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorCoreService;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;

/**
 * Class OverDriveBooksVendorService.
 */
class OverDriveBooksVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 14;

    private $apiClient;
    private $httpClient;

    /**
     * OverDriveBooksVendorService constructor.
     *
     * @param vendorCoreService $vendorCoreService
     *   Service with shared vendor functions
     * @param ClientInterface $httpClient
     *   Http client to send api requests
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(VendorCoreService $vendorCoreService, ClientInterface $httpClient, Client $apiClient)
    {
        parent::__construct($vendorCoreService);

        $this->httpClient = $httpClient;
        $this->apiClient = $apiClient;
    }

    /**
     * {@inheritdoc}
     *
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        $this->loadConfig();

        $status = new VendorStatus();

        try {
            $this->progressStart('Starting eReolen Global import from overdrive API');

            $totalCount = (0 !== $this->limit) ? $this->limit : $this->apiClient->getTotalProducts();

            $batchSize = ($this->limit > 0 && $this->limit < self::BATCH_SIZE) ? $this->limit : self::BATCH_SIZE;
            $offset = 0;

            do {
                $products = $this->apiClient->getProducts($batchSize, $offset);

                $isbnImageUrlArray = [];
                foreach ($products as $product) {
                    $coverImageUrl = $product->images->cover->href ?? null;

                    foreach ($product->formats as $format) {
                        foreach ($format->identifiers as $identifier) {
                            if (IdentifierType::ISBN === strtolower($identifier->type)) {
                                if (!empty($identifier->value)) {
                                    $isbnImageUrlArray[$identifier->value] = $coverImageUrl;
                                }
                            }
                        }
                    }
                }

                $this->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN);

                $this->progressMessageFormatted($status);
                $this->progressAdvance();

                $offset += self::BATCH_SIZE;
            } while ($offset < $totalCount);

            $this->progressFinish();

            return VendorImportResultMessage::success($status);
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
}
