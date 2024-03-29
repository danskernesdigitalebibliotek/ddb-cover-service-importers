<?php

/**
 * @file
 * Service for updating book covers from OverDrive.
 */

namespace App\Service\VendorService\OverDrive;

use App\Exception\UninitializedPropertyException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\OverDrive\Api\Client;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceImporterInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

/**
 * Class OverDriveBooksVendorService.
 */
class OverDriveBooksVendorService implements VendorServiceImporterInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    public const VENDOR_ID = 14;

    // Pattern for matching URLs for "Upcomming Release" generic cover. E.g.
    // https://img1.od-cdn.com/ImageType-100/8174-1/{00000000-0000-0000-0000-000000000229}Img100.jpg
    // https://img1.od-cdn.com/ImageType-100/0292-1/{00000000-0000-0000-0000-000000000303}Img100.jpg
    // https://img1.od-cdn.com/ImageType-100/1219-1/{00000000-0000-0000-0000-000000000007}Img100.jpg
    protected const GENERIC_COVERS_PATTERN = '/https:\/\/img1.od-cdn.com\/.*\/{(0|-)*\d*}Img100.jpg/';

    /**
     * OverDriveBooksVendorService constructor.
     *
     * @param Client $apiClient
     *   Api client for the OverDrive API
     */
    public function __construct(
        private readonly Client $apiClient
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return VendorImportResultMessage
     *
     * @throws InvalidArgumentException
     * @throws UnknownVendorServiceException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
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

                    // Exclude (set null) URLs for known generic covers
                    if (null !== $coverImageUrl) {
                        $coverImageUrl = $this->isGenericCover($coverImageUrl) ? null : $coverImageUrl;
                    }

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
        $clientId = $vendor->getDataServerUser();
        $clientSecret = $vendor->getDataServerPassword();

        if (null === $libraryAccountEndpoint || null === $clientId || null === $clientSecret) {
            throw new UninitializedPropertyException('Incomplete config for '.self::class);
        }

        $this->apiClient->setLibraryAccountEndpoint($libraryAccountEndpoint);
        $this->apiClient->setCredentials($clientId, $clientSecret);
    }

    /**
     * Is the cover a known generic cover.
     *
     * @param string $imageUrl
     *   The image URL to check
     *
     * @return bool
     *   True for known generic covers
     */
    private function isGenericCover(string $imageUrl): bool
    {
        return (bool) \preg_match(self::GENERIC_COVERS_PATTERN, $imageUrl);
    }
}
