<?php
/**
 * @file
 * Abstract vendor for tsv file imports.
 */

namespace App\Service\VendorService;

use App\Exception\UnknownVendorResourceFormatException;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Iterator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class AbstractTsvVendorService.
 */
abstract class AbstractTsvVendorService implements VendorServiceImporterInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected string $vendorArchiveDir = 'AbstractTsvVendor';
    protected string $vendorArchiveName = 'covers.tsv';
    protected string $fieldDelimiter = "\t";
    protected bool $sheetHasHeaderRow = true;
    protected array $sheetFields = [];
    protected int $tsvBatchSize = 100;

    /**
     * AbstractTsvVendorService constructor.
     *
     * @param string $resourcesDir
     *   The application resource dir
     */
    public function __construct(
        protected string $resourcesDir,
        protected CsvReaderService $csvReaderService,
        protected HttpClientInterface $httpClient,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        try {
            $this->progressStart('Opening resource: "'.$this->vendorArchiveName.'"');

            $totalRows = 0;
            $pidArray = [];
            $status = new VendorStatus();
            $fields = $this->sheetFields;

            $iterator = $this->getSheetIterator();
            foreach ($iterator as $row) {
                if ($this->sheetHasHeaderRow && 0 === $totalRows) {
                    // Header row exists, so lets try finding the right cell numbers.
                    $fields = $this->findCellName($row);

                    // First row in the tsv file contains the headers.
                    if (!array_key_exists('faust', $fields) || !array_key_exists('ppid', $fields) || !array_key_exists('url', $fields)) {
                        throw new UnknownVendorResourceFormatException('Unknown columns in tsv resource file.');
                    }
                } else {
                    if (!empty($fields)) {
                        $basisPid = $row[$fields['ppid']];
                        $imageUrl = $row[$fields['url']];
                        if (!empty($basisPid) && !empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                            $pidArray[$basisPid] = $imageUrl;
                        }
                    } else {
                        throw new UnknownVendorResourceFormatException('Header row was not found.');
                    }
                }

                ++$totalRows;

                if ($this->limit && $totalRows >= $this->limit) {
                    break;
                }

                if (0 === $totalRows % $this->tsvBatchSize) {
                    $this->vendorCoreService->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                    $pidArray = [];

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();
                }
            }

            $this->vendorCoreService->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);
            $this->progressFinish();

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Get a tsv file Iterator reference for the import source.
     *
     * @return Iterator
     */
    protected function getSheetIterator(): Iterator
    {
        $resourceDirectories = [$this->resourcesDir.'/'.$this->vendorArchiveDir];

        $fileLocator = new FileLocator($resourceDirectories);
        $filePath = $fileLocator->locate($this->vendorArchiveName);

        return $this->csvReaderService->read($filePath, $this->fieldDelimiter);
    }

    /**
     * Helper function to get cell names.
     *
     * @param array $fields
     *   The first row from the tsv file
     *
     * @return array
     *   Keys are cell names and values are cell numbers
     */
    protected function findCellName(array $fields): array
    {
        $fields = array_map(fn ($field) => mb_strtolower((string) $field), $fields);

        return array_flip($fields);
    }

    /**
     * Download the TSV file to local filesystem.
     *
     * @throws TransportExceptionInterface
     */
    protected function downloadTsv(string $location, string $url): void
    {
        $response = $this->httpClient->request('GET', $url);

        $path = dirname($location);
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $dest = fopen($location, 'w');
        stream_copy_to_stream(StreamWrapper::createResource($response, $this->httpClient), $dest);
        fclose($dest);
    }
}
