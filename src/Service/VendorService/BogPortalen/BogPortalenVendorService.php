<?php
/**
 * @file
 * Service for updating data from 'Bogportalen'.
 */

namespace App\Service\VendorService\BogPortalen;

use App\Exception\DownloadFailedException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Class BogPortalenVendorService.
 */
class BogPortalenVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    private const VENDOR_ID = 1;
    private const VENDOR_ARCHIVE_NAMES = ['BOP-ProductAll.zip', 'BOP-ProductAll-EXT.zip', 'BOP-Actual.zip', 'BOP-Actual-EXT.zip'];
    private const VENDOR_ARCHIVE_DIR = 'BogPortalen';
    private const VENDOR_ROOT_DIR = 'Public';

    private ?string $ftpHost;
    private ?string $ftpPassword;
    private ?string $ftpUsername;

    /**
     * BogPortalenVendorService constructor.
     *
     * @param string $resourcesDir
     */
    public function __construct(
        protected string $resourcesDir,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnknownVendorServiceException
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        $this->loadConfig();
        $status = new VendorStatus();

        foreach (self::VENDOR_ARCHIVE_NAMES as $archive) {
            try {
                $this->progressMessage('Downloading '.$archive.' archive....');
                $this->progressAdvance();

                $localArchivePath = $this->resourcesDir.'/'.$this::VENDOR_ARCHIVE_DIR.'/'.$archive;

                $this->updateArchive($localArchivePath, $archive);

                $this->progressMessage('Getting filenames from archive: "'.$archive.'"');
                $this->progressAdvance();

                $files = $this->listZipContents($localArchivePath);
                $isbnList = $this->getIsbnNumbers($files);

                $this->progressMessage('Removing ISBNs not found in archive');
                $this->progressAdvance();

                // @TODO Dispatch delete event to deleteProcessor
                // $deleted = $this->deleteRemovedMaterials($isbnList);

                $offset = 0;
                $count = $this->limit ?: \count($isbnList);

                while ($offset < $count) {
                    $isbnBatch = \array_slice($isbnList, $offset, self::BATCH_SIZE, true);

                    $isbnImageUrlArray = $this->buildIsbnImageUrlArray($isbnBatch);
                    $this->vendorCoreService->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();

                    $offset += self::BATCH_SIZE;
                }

                if (file_exists($localArchivePath)) {
                    unlink($localArchivePath);
                }

                if ($this->limit && $offset >= $this->limit) {
                    break;
                }
            } catch (\Exception $e) {
                return VendorImportResultMessage::error($e->getMessage());
            }
        }

        $this->progressFinish();

        $this->vendorCoreService->releaseLock($this->getVendorId());

        return VendorImportResultMessage::success($status);
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        // Set FTP adapter configuration.
        if (!empty($vendor->getDataServerUser()) && !empty($vendor->getDataServerPassword()) && !empty($vendor->getDataServerURI())) {
            $this->ftpUsername = $vendor->getDataServerUser();
            $this->ftpPassword = $vendor->getDataServerPassword();
            $this->ftpHost = $vendor->getDataServerURI();
        } else {
            throw new \InvalidArgumentException('Missing configuration');
        }
    }

    /**
     * Build array of image urls keyed by isbn.
     *
     * @return string[]
     *
     * @throws UnknownVendorServiceException
     * @psalm-return array<string, string>
     */
    private function buildIsbnImageUrlArray(array &$isbnList): array
    {
        $isbnArray = [];
        foreach ($isbnList as $isbn) {
            $isbnArray[$isbn] = $this->getVendorsImageUrl($isbn);
        }

        return $isbnArray;
    }

    /**
     * Get Vendors image URL from ISBN.
     *
     * @throws UnknownVendorServiceException
     */
    private function getVendorsImageUrl(string $isbn): string
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        return $vendor->getImageServerURI().$isbn.'.jpg';
    }

    /**
     * Update local copy of vendors archive.
     *
     * @param string $localArchive
     *   Local full file path
     * @param string $remoteArchive
     *   Filename for the archive
     *
     * @throws DownloadFailedException
     */
    private function updateArchive(string $localArchive, string $remoteArchive): void
    {
        $path = dirname($localArchive);
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $fh = fopen($localArchive, 'w');
        $ftp = ftp_connect($this->ftpHost);
        if (false !== $ftp) {
            if (!ftp_login($ftp, $this->ftpUsername, $this->ftpPassword)) {
                throw new DownloadFailedException('FTP login failed');
            }
        }

        if (false !== $ftp || false !== $fh) {
            if (!ftp_chdir($ftp, $this::VENDOR_ROOT_DIR)) {
                throw new DownloadFailedException('FTP change dir failed: '.$this::VENDOR_ROOT_DIR);
            }
            if (!ftp_pasv($ftp, true)) {
                throw new DownloadFailedException('FTP change to passive mode failed');
            }
            if (!ftp_fget($ftp, $fh, $remoteArchive)) {
                throw new DownloadFailedException('FTP download failed: '.$remoteArchive);
            }
        }
    }

    /**
     * Get list of files in ZIP archive.
     *
     * @param $path
     *   The path of the archive in the local filesystem
     *
     * @throws FileNotFoundException
     */
    private function listZipContents(string $path): array
    {
        $fileNames = [];

        // Using the native PHP function to extract the file names because we
        // don't care about metadata. This has significantly better performance
        // then the equivalent Flysystem method because the Flysystem method
        // also extracts metadata for all files.
        $zip = new \ZipArchive();
        $zipReader = $zip->open($path);

        if ($zipReader) {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $fileNames[] = $zip->getNameIndex($i);
            }
        } else {
            throw new FileNotFoundException('Error when reading '.$path);
        }

        return $fileNames;
    }

    /**
     * Get valid and unique ISBNs from list of paths.
     *
     * @return string[]
     * @psalm-return array<int, string>
     */
    private function getIsbnNumbers(array &$filePaths): array
    {
        $isbnList = [];

        foreach ($filePaths as $filePath) {
            // Example path: 'Archive/DBK-7003718/DBK-7003718-9788799933815.xml'
            $pathParts = pathinfo((string) $filePath);
            $fileName = $pathParts['filename'];
            $nameParts = explode('-', $fileName);
            $isbn = array_pop($nameParts);

            // Ensure that the found string is a number to filter out files with wrong or incomplete ISBNs.
            $temp = (int) $isbn;
            $temp = (string) $temp;
            if (($isbn === $temp) && (13 === strlen($isbn))) {
                $isbnList[] = $isbn;
            }
        }

        // Ensure there are no duplicate values in the array.
        // Double 'array_flip' performs 150x faster than 'array_unique'
        // https://stackoverflow.com/questions/8321620/array-unique-vs-array-flip
        return array_flip(array_flip($isbnList));
    }
}
