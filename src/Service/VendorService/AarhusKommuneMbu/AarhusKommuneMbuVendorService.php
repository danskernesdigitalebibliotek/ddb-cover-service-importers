<?php
/**
 * @file
 * Service for updating data from 'AarhusKommuneMBU' tsv file.
 */

namespace App\Service\VendorService\AarhusKommuneMbu;

use App\Service\VendorService\AbstractTsvVendorService;
use App\Utils\Message\VendorImportResultMessage;
use League\Flysystem\UnreadableFileException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class HerningBibVendorService.
 */
class AarhusKommuneMbuVendorService extends AbstractTsvVendorService
{
    protected const VENDOR_ID = 18;
    private const TSV_URL = 'https://drive.google.com/uc?id=1zmXcKSWOvIy5-2-3PODP6gy4lIzZ9k3I';

    protected string $vendorArchiveDir = 'AarhusKommuneMbu';
    protected string $vendorArchiveName = 'index.tsv';
    protected string $fieldDelimiter = ' ';
    protected bool $sheetHasHeaderRow = false;
    protected array $sheetFields = ['ppid' => 0, 'url' => 1];

    private HttpClientInterface $client;
    private string $resourcesDir;
    private string $projectDir;

    /**
     * HerningBibVendorService constructor.
     *
     * @param string $resourcesDir
     * @param string $projectDir
     * @param HttpClientInterface $client
     */
    public function __construct(string $resourcesDir, string $projectDir, HttpClientInterface $client)
    {
        // Resource files is loaded from online location
        parent::__construct($resourcesDir, $projectDir, $client);

        $this->client = $client;
        $this->projectDir = $projectDir;
        $this->resourcesDir = $resourcesDir;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnreadableFileException
     */
    public function load(): VendorImportResultMessage
    {
        try {
            $this->downloadTsv();
        } catch (ExceptionInterface $exception) {
            throw new UnreadableFileException('Failed to get TSV file from CDN');
        }

        return parent::tsvLoad(true);
    }

    /**
     * Download the TSV file to local filesystem.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function downloadTsv()
    {
        $location = $this->resourcesDir.'/'.$this->vendorArchiveDir;
        if (!file_exists($location)) {
            mkdir($location);
        }

        $response = $this->client->request('GET', $this::TSV_URL);
        $fileHandler = fopen($location.'/'.$this->vendorArchiveName, 'w');
        foreach ($this->client->stream($response) as $chunk) {
            fwrite($fileHandler, $chunk->getContent());
        }
        fclose($fileHandler);
    }
}
