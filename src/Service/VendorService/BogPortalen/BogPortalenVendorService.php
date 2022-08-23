<?php
/**
 * @file
 * Service for updating data from 'Bogportalen'.
 */

namespace App\Service\VendorService\BogPortalen;

use App\Exception\UnknownVendorServiceException;
use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\VendorService\FtpDownloadService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceImporterInterface;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Class BogPortalenVendorService.
 */
class BogPortalenVendorService implements VendorServiceImporterInterface, VendorServiceSingleIdentifierInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    private const VENDOR_ID = 1;
    private const VENDOR_ARCHIVE_NAMES = ['BOP-ProductAll.zip', 'BOP-ProductAll-EXT.zip', 'BOP-Actual.zip', 'BOP-Actual-EXT.zip'];
    private const VENDOR_ARCHIVE_DIR = 'BogPortalen';
    private const VENDOR_ROOT_DIR = 'Public';

    private string $ftpHost;
    private string $ftpPassword;
    private string $ftpUsername;

    /**
     * BogPortalenVendorService constructor.
     *
     * @param string $resourcesDir
     * @param FtpDownloadService $FTPService
     */
    public function __construct(
        protected string $resourcesDir,
        private readonly FtpDownloadService $FTPService,
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

        foreach (self::VENDOR_ARCHIVE_NAMES as $remoteArchive) {
            try {
                $this->progressMessage('Downloading '.$remoteArchive.' archive....');
                $this->progressAdvance();

                $localArchivePath = $this->resourcesDir.'/'.$this::VENDOR_ARCHIVE_DIR.'/'.$remoteArchive;

                $this->FTPService->download($this->ftpHost, $this->ftpUsername, $this->ftpPassword, self::VENDOR_ROOT_DIR, $localArchivePath, $remoteArchive);

                $this->progressMessage('Getting filenames from archive: "'.$remoteArchive.'"');
                $this->progressAdvance();

                $files = $this->listZipContents($localArchivePath);
                $isbnList = $this->getIsbnNumbers($files);

                $this->progressMessage('Removing ISBNs not found in archive');
                $this->progressAdvance();

                // @TODO Dispatch delete event to deleteProcessor
                // $deleted = $this->deleteRemovedMaterials($isbnList);

                $offset = 0;
                $count = $this->limit ?: count($isbnList);

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
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifierType($type)) {
            throw new UnsupportedIdentifierTypeException('Unsupported single identifier type: '.$type);
        }

        $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

        $item = new UnverifiedVendorImageItem();
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);
        $item->setVendor($vendor);
        $item->setOriginalFile($this->getVendorsImageUrl($identifier));

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifierType(string $type): bool
    {
        return IdentifierType::ISBN === $type;
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
        $ftpUsername = $vendor->getDataServerUser();
        $ftpPassword = $vendor->getDataServerPassword();
        $ftpHost = $vendor->getDataServerURI();
        if (!empty($ftpUsername) && !empty($ftpPassword) && !empty($ftpHost)) {
            $this->ftpUsername = $ftpUsername;
            $this->ftpPassword = $ftpPassword;
            $this->ftpHost = $ftpHost;
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
        // don't care about metadata.
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
