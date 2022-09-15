<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService;

use App\Service\DataWell\DataWellClient;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;

/**
 * Class ComicsPlusVendorService.
 */
abstract class AbstractDataWellVendorService implements VendorServiceImporterInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected array $datawellQueries = [];

    /**
     * DataWellVendorService constructor.
     *
     * @param DataWellClient $datawell
     *   For searching the data well
     */
    public function __construct(
        protected readonly DataWellClient $datawell
    ) {
    }

    /**
     * Extract cover url from response.
     *
     * @param object $jsonContent
     *   Array of the json decoded data
     *
     * @return array<string, ?string>
     *   Array of all pid => url pairs found in response
     */
    abstract protected function extractData(object $jsonContent): array;

    /**
     * @{@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        // @TODO: Refactor to remove the need for loadConfig
        if (method_exists($this, 'loadConfig')) {
            $this->loadConfig();
        }

        $status = new VendorStatus();

        $this->progressStart('Search data well for: "'.implode(', ', $this->datawellQueries).'"');

        $offset = 0;
        try {
            foreach ($this->datawellQueries as $datawellQuery) {
                do {
                    // Search the data well with given query.
                    [$jsonContent, $more, $offset] = $this->datawell->search($datawellQuery, $offset);

                    // Extract
                    $pidArray = $this->extractData($jsonContent);
                    $batchSize = count($pidArray);

                    // Remove empty elements.
                    $pidArray = array_filter($pidArray);

                    $this->vendorCoreService->updateOrInsertMaterials(
                        $status,
                        $pidArray,
                        IdentifierType::PID,
                        $this->getVendorId(),
                        $this->withUpdatesDate,
                        $this->withoutQueue,
                        $batchSize
                    );

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();

                    if ($this->limit && $status->records >= $this->limit) {
                        $more = false;
                    }
                } while ($more);
            }

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }
}
