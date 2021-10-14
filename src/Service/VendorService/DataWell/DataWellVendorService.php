<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\DataWell;

use App\Service\VendorService\DataWell\DataConverter\IversePublicUrlConverter;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;

/**
 * Class DataWellVendorService.
 */
class DataWellVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 4;
    private const VENDOR_ARCHIVE_NAME = 'comics+';

    private DataWellSearchService $datawell;

    /**
     * DataWellVendorService constructor.
     *
     * @param DataWellSearchService $datawell
     *   For searching the data well
     */
    public function __construct(DataWellSearchService $datawell)
    {
        $this->datawell = $datawell;
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
                [$pidArray, $more, $offset] = $this->datawell->search('comics plus', $offset);

                // Convert images url from 'medium' to 'large'
                IversePublicUrlConverter::convertArrayValues($pidArray);

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
     * Set config fro service from DB vendor object.
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        // Set the service access configuration from the vendor.
        $this->datawell->setSearchUrl($vendor->getDataServerURI());
        $this->datawell->setUser($vendor->getDataServerUser());
        $this->datawell->setPassword($vendor->getDataServerPassword());
    }
}
