<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\DataWell;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\DataWell\DataConverter\IversePublicUrlConverter;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorCoreService;
use App\Service\VendorService\VendorServiceInterface;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;

/**
 * Class DataWellVendorService.
 */
class DataWellVendorService extends AbstractBaseVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 4;
    private const VENDOR_ARCHIVE_NAME = 'comics+';

    private $datawell;

    /**
     * DataWellVendorService constructor.
     *
     * @param vendorCoreService $vendorCoreService
     *   Service with shared vendor functions
     * @param dataWellSearchService $datawell
     *   For searching the data well
     */
    public function __construct(VendorCoreService $vendorCoreService, DataWellSearchService $datawell)
    {
        parent::__construct($vendorCoreService);

        $this->datawell = $datawell;
    }

    /**
     * @{@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
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
                $this->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID, $batchSize);

                $this->progressMessageFormatted($status);
                $this->progressAdvance();

                if ($this->limit && $status->records >= $this->limit) {
                    $more = false;
                }
            } while ($more);

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config fro service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    private function loadConfig(): void
    {
        // Set the service access configuration from the vendor.
        $this->datawell->setSearchUrl($this->getVendor()->getDataServerURI());
        $this->datawell->setUser($this->getVendor()->getDataServerUser());
        $this->datawell->setPassword($this->getVendor()->getDataServerPassword());
    }
}
