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
 * Class PressReaderService.
 */
class PressReaderVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 19;
    private const VENDOR_ARCHIVE_NAME = 'pressreader';

    private DataWellSearchService $datawell;

    private string $urlPattern = 'https://i.prcdn.co/img?cid=%s&page=1&width=1200';
    private VendorImageValidatorService $imageValidatorService;

    /**
     * DataWellVendorService constructor.
     *
     * @param DataWellSearchService $datawell
     *   For searching the data well
     */
    public function __construct(DataWellSearchService $datawell, VendorImageValidatorService $imageValidatorService)
    {
        $this->datawell = $datawell;
        $this->imageValidatorService = $imageValidatorService;
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
                // Search the data well for material with acSource set to "comics plus".
                [$pidArray, $more, $offset] = $this->datawell->search(self::VENDOR_ARCHIVE_NAME, $offset);

                // Transform and clean up results.
                $this->TransformUrls($pidArray);
                $pidArray = array_filter($pidArray);

                $batchSize = \count($pidArray);
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
     * Transform the URL from the datawell to CDN https://i.prcdn.co/img?cid={$id}&page=1&width=1200.
     *
     * @param array $pidArray
     *   The array of PIDs indexed by pid containing URLs.
     */
    private function TransformUrls(array &$pidArray): void
    {
        foreach ($pidArray as $pid => &$url) {
            list($agency, $id) = explode(':', $pid);
            $url =  sprintf($this->urlPattern, $id);

            // The press reader CDN insert at special image saying that the content is not updated for newest news
            // cover. See https://i.prcdn.co/img?cid=9L09&page=1&width=1200, but the size will be under 30Kb, so we have
            // this extra test.
            $header = $this->imageValidatorService->remoteImageHeader('cf-polished', $url);
            if (!empty($header)) {
                $header = reset($header);
                list($label, $size) = explode('=', $header);
                if ($size < 30000) {
                    // Size to little set it to null.
                    $url = null;
                }
            }
            else {
                // Size header not found.
                $url = null;
            }
        }
    }
}
