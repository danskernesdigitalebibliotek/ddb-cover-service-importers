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
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class HerningBibVendorService.
 */
class AarhusKommuneMbuVendorService extends AbstractTsvVendorService
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    public const VENDOR_ID = 18;
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
     * @param string $resourcesDir
     * @param CsvReaderService $csvReaderService
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        protected string $resourcesDir,
        protected CsvReaderService $csvReaderService,
        protected HttpClientInterface $httpClient,
    ) {
        parent::__construct($resourcesDir, $csvReaderService, $httpClient);

        $this->fieldDelimiter = ' ';
        $this->location = $resourcesDir.'/'.$this->vendorArchiveDir.'/'.$this->vendorArchiveName;
    }

    /**
     * {@inheritdoc}
     *
     * @throws FileNotFoundException
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        try {
            $this->downloadTsv($this->location, self::TSV_URL);
        } catch (TransportExceptionInterface $e) {
            throw new FileNotFoundException('Failed to get TSV file from CDN', $e->getCode(), $e);
        }

        try {
            $this->progressStart('Opening resource: "'.$this->vendorArchiveName.'"');

            $totalRows = 0;
            $faustArray = [];
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
                            // This is a hack as this vendor uses katalog PID, but want to be able to search on faust,
                            // so we index them by faust and will make the mapping to katalog in no-hit processing.
                            if (preg_match('/^300751-katalog:(\d+)/', (string) $basisPid, $matches)) {
                                $faustArray[$matches[1]] = $imageUrl;
                            }
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
                    $this->vendorCoreService->updateOrInsertMaterials($status, $faustArray, IdentifierType::FAUST, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                    $faustArray = [];

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();
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
}
