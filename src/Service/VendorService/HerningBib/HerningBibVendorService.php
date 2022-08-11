<?php
/**
 * @file
 * Service for updating data from 'boardgamegeek' tsv file.
 */

namespace App\Service\VendorService\HerningBib;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\CsvReaderService;
use App\Utils\Message\VendorImportResultMessage;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class HerningBibVendorService.
 */
class HerningBibVendorService extends AbstractTsvVendorService
{
    protected const VENDOR_ID = 17;
    private const TSV_URL = 'https://cdn.herningbib.dk/coverscan/index.tsv';

    protected string $vendorArchiveDir = 'HerningBib';
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
        try {
            $this->downloadTsv($this->location, self::TSV_URL);
        } catch (TransportExceptionInterface $e) {
            throw new FileNotFoundException('Failed to get TSV file from CDN', $e->getCode(), $e);
        }

        return parent::load();
    }
}
