<?php
/**
 * @file
 * Service for updating data from 'boardgamegeek' tsv file.
 */

namespace App\Service\VendorService\HerningBib;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Service\VendorService\CsvReaderService;
use App\Utils\Message\VendorImportResultMessage;
use GuzzleHttp\ClientInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\UnreadableFileException;

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

    private ClientInterface $httpClient;
    private Filesystem $local;
    private string $location;

    /**
     * HerningBibVendorService constructor.
     *
     * @param ClientInterface $httpClient
     * @param Filesystem $local
     */
    public function __construct(ClientInterface $httpClient, Filesystem $local, CsvReaderService $csvReaderService)
    {
        // Resource files is loaded from online location
        parent::__construct('', $csvReaderService);

        $this->location = $this->vendorArchiveDir.'/'.$this->vendorArchiveName;

        $this->httpClient = $httpClient;
        $this->local = $local;
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

        return parent::load();
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
