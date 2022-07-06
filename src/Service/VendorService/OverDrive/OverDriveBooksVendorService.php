<?php

/**
 * @file
 * Service for updating book covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\OverDrive\Api\Client;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;

/**
 * Class OverDriveBooksVendorService.
 */
class OverDriveBooksVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 14;

    /**
     * OverDriveBooksVendorService constructor.
     *
     * @param ClientInterface $httpClient
     *   Http client to send api requests
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly Client $apiClient
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     * @throws UnknownVendorServiceException
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
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
                            if (IdentifierType::ISBN === strtolower((string) $identifier->type)) {
                                if (!empty($identifier->value)) {
                                    $isbnImageUrlArray[$identifier->value] = $coverImageUrl;
                                }
                            }
                        }
                    }
                }

                $this->vendorCoreService->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                $this->progressMessageFormatted($status);
                $this->progressAdvance();

                $offset += self::BATCH_SIZE;
            } while ($offset < $totalCount);

            $this->progressFinish();

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        $libraryAccountEndpoint = $vendor->getDataServerURI();
        $this->apiClient->setLibraryAccountEndpoint($libraryAccountEndpoint);

        $clientId = $vendor->getDataServerUser();
        $clientSecret = $vendor->getDataServerPassword();
        $this->apiClient->setCredentials($clientId, $clientSecret);
    }
}
