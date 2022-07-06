<?php

/**
 * @file
 * Get cover from PressReader based on data well searches.
 */

namespace App\Service\VendorService\PressReader;

use App\Service\VendorService\DataWell\DataWellSearchService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorImageValidatorService;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;

/**
 * Class PressReaderVendorService.
 */
class PressReaderVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 19;
    private const VENDOR_ARCHIVE_NAME = 'pressreader';
    private const URL_PATTERN = 'https://i.prcdn.co/img?cid=%s&page=1&width=1200';
    private const MIN_IMAGE_SIZE = 40000;

    /**
     * DataWellVendorService constructor.
     *
     * @param DataWellSearchService $datawell
     *   For searching the data well
     */
    public function __construct(
        private readonly DataWellSearchService $datawell,
        private readonly VendorImageValidatorService $imageValidatorService
    ) {
    }

    /**
     * @{@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        // We're lazy loading the config to avoid errors from missing config values on dependency injection
        $this->loadConfig();

        $status = new VendorStatus();

        $this->progressStart('Search data well for: "'.self::VENDOR_ARCHIVE_NAME.'"');

        $offset = 1;
        try {
            do {
                // Search the data well for material with acSource set to "pressreader".
                [$pidArray, $more, $offset] = $this->datawell->search(self::VENDOR_ARCHIVE_NAME, $offset);
                $this->transformUrls($pidArray);

                // The press reader CDN insert at special image saying that the content is not updated for newest news
                // cover. See https://i.prcdn.co/img?cid=9L09&page=1&width=1200, but the size will be under 40Kb, so we have
                // this extra test.
                $pidArray = array_filter($pidArray, function ($url) {
                    $header = $this->imageValidatorService->remoteImageHeader('cf-polished', $url);
                    if (!empty($header)) {
                        $header = reset($header);
                        [$label, $size] = explode('=', $header);
                        if ($size < $this::MIN_IMAGE_SIZE) {
                            // Size to little set it to null.
                            return false;
                        }
                    } else {
                        // Size header not found.
                        return false;
                    }

                    return true;
                });

                $batchSize = \count((array) $pidArray);
                $this->vendorCoreService->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, $batchSize);

                $this->progressMessageFormatted($status);
                $this->progressAdvance();

                if ($this->limit && $status->records >= $this->limit) {
                    $more = false;
                }
            } while ($more);

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config from service from DB vendor object.
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        // Set the service access configuration from the vendor.
        $this->datawell->setSearchUrl($vendor->getDataServerURI());
        $this->datawell->setUser($vendor->getDataServerUser());
        $this->datawell->setPassword($vendor->getDataServerPassword());
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
