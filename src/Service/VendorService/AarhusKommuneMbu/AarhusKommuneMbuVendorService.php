<?php
/**
 * @file
 * Service for updating data from 'boardgamegeek' tsv file.
 */

namespace App\Service\VendorService\AarhusKommuneMbu;

use App\Exception\UnknownVendorResourceFormatException;
use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\CsvReaderService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use GuzzleHttp\ClientInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\UnreadableFileException;

/**
 * Class HerningBibVendorService.
 */
class AarhusKommuneMbuVendorService extends AbstractTsvVendorService
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 18;
    private const TSV_URL = 'https://drive.google.com/uc?id=1zmXcKSWOvIy5-2-3PODP6gy4lIzZ9k3I';

    protected string $vendorArchiveDir = 'AarhusKommuneMbu';
    protected string $vendorArchiveName = 'index.tsv';
    protected string $fieldDelimiter = ' ';
    protected bool $sheetHasHeaderRow = false;
    protected array $sheetFields = ['ppid' => 0, 'url' => 1];
    private readonly string $location;

    /**
     * HerningBibVendorService constructor.
     *
     * @param ClientInterface $httpClient
     * @param Filesystem $local
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly Filesystem $local,
        CsvReaderService $csvReaderService
    ) {
        // Resource files is loaded from online location
        parent::__construct('', $csvReaderService);

        $this->location = $this->vendorArchiveDir.'/'.$this->vendorArchiveName;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnreadableFileException
     */
    public function load(): VendorImportResultMessage
    {
        $tsv = $this->getTsv($this->location, self::TSV_URL);

        if (!$tsv) {
            throw new UnreadableFileException('Failed to get TSV file from CDN');
        }

        $this->vendorArchiveDir = $this->local->getAdapter()->getPathPrefix().$this->vendorArchiveDir;

        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        try {
            $this->progressStart('Opening resource: "'.$this->vendorArchiveName.'"');

            $reader = $this->getSheetIterator();

            $totalRows = 0;
            $faustArray = [];
            $status = new VendorStatus();

            foreach ($reader->getSheetIterator() as $sheet) {
                $fields = $this->sheetFields;
                foreach ($sheet->getRowIterator() as $row) {
                    $cellsArray = $row->getCells();
                    if (!empty($fields)) {
                        $basisPid = $cellsArray[$fields['ppid']]->getValue();
                        $imageUrl = $cellsArray[$fields['url']]->getValue();
                        if (!empty($basisPid) && !empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                            // This is a hack as this vendor uses katalog PID, but want to be able to search on faust,
                            // so we index them by faust and will make the mapping to katalog in no-hit processing.
                            if (preg_match('/^300751-katalog:(\d+)/', (string) $basisPid, $matches)) {
                                $faustArray[$matches[1]] = $imageUrl;
                            }
                        }
                    } else {
                        throw new UnknownVendorResourceFormatException('Header row was not found.');
                    }

                    ++$totalRows;

                    if ($this->limit && $totalRows >= $this->limit) {
                        break;
                    }

                    if (0 === $totalRows % $this->tsvBatchSize) {
                        $this->vendorCoreService->updateOrInsertMaterials($status, $faustArray, IdentifierType::FAUST, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                        $faustArray = [];

                        $this->progressMessageFormatted($status);
                        $this->progressAdvance();
                    }
                }
            }

            $this->vendorCoreService->updateOrInsertMaterials($status, $faustArray, IdentifierType::FAUST, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);
            $this->progressFinish();

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Download the TSV file to local filesystem.
     */
    private function getTsv(string $location, string $url): bool
    {
        $response = $this->httpClient->get($url);

        return $this->local->putStream($location, $response->getBody()->detach());
    }
}
