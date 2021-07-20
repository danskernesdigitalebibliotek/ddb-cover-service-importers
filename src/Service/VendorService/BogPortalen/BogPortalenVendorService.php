<?php
/**
 * @file
 * Service for updating data from 'Bogportalen'.
 */

namespace App\Service\VendorService\BogPortalen;

use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use League\Flysystem\Filesystem;
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

    private Filesystem $local;
    private Filesystem $ftp;

    /**
     * BogPortalenVendorService constructor.
     *
     * @param filesystem $local
     *   Flysystem adapter for local filesystem
     * @param filesystem $ftp
     *   Flysystem adapter for remote ftp server
     */
    public function __construct(Filesystem $local, Filesystem $ftp)
    {
        $this->local = $local;
        $this->ftp = $ftp;
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

                $this->updateArchive($archive);

                $this->progressMessage('Getting filenames from archive: "'.$archive.'"');
                $this->progressAdvance();

                $localArchivePath = $this->local->getAdapter()->getPathPrefix().$archive;
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
                    $this->vendorCoreService->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdates, $this->withoutQueue, self::BATCH_SIZE);

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();

                    $offset += self::BATCH_SIZE;
                }

                $this->local->delete($archive);

                if ($this->limit && $offset >= $this->limit) {
                    break;
                }
            } catch (\Exception $e) {
                $this->logStatusMetrics($status);

                return VendorImportResultMessage::error($e->getMessage());
            }
        }

        $this->logStatusMetrics($status);
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
        $adapter = $this->ftp->getAdapter();
        $adapter->setUsername($vendor->getDataServerUser());
        $adapter->setPassword($vendor->getDataServerPassword());
        $adapter->setHost($vendor->getDataServerURI());
    }

    /**
     * Build array of image urls keyed by isbn.
     *
     * @param array $isbnList
     *
     * @return string[]
     *
     * @throws UnknownVendorServiceException
     *
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
     * @param string $isbn
     *
     * @return string
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
     * @param string $archive
     *   Filename for the archive
     *
     * @return bool
     *
     * @throws \League\Flysystem\FileNotFoundException
     */
    private function updateArchive(string $archive): bool
    {
        // @TODO Error handling for missing archive
        return $this->local->putStream($archive, $this->ftp->readStream($archive));
    }

    /**
     * Get list of files in ZIP archive.
     *
     * @param $path
     *   The path of the archive in the local filesystem
     *
     * @return (false|string)[] List of filenames
     *
     * @throws FileNotFoundException
     *
     * @psalm-return list<false|string>
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
            throw new FileNotFoundException('Error: '.$zipReader.' when reading '.$path);
        }

        return $fileNames;
    }

    /**
     * Get valid and unique ISBN numbers from list of paths.
     *
     * @param array $filePaths
     *
     * @return string[]
     *
     * @psalm-return array<int, string>
     */
    private function getIsbnNumbers(array &$filePaths): array
    {
        $isbnList = [];

        foreach ($filePaths as $filePath) {
            // Example path: 'Archive/DBK-7003718/DBK-7003718-9788799933815.xml'
            $pathParts = pathinfo($filePath);
            $fileName = $pathParts['filename'];
            $nameParts = explode('-', $fileName);
            $isbn = array_pop($nameParts);

            // Ensure that the found string is a number to filter out
            // files with wrong or incomplete isbn numbers.
            $temp = (int) $isbn;
            $temp = (string) $temp;
            if (($isbn === $temp) && (13 === strlen($isbn))) {
                $isbnList[] = $isbn;
            } else {
                // @TODO: Should we log invalid ISBNs here?
            }
        }

        // Ensure there are no duplicate values in the array.
        // Double 'array_flip' performs 150x faster than 'array_unique'
        // https://stackoverflow.com/questions/8321620/array-unique-vs-array-flip
        return array_flip(array_flip($isbnList));
    }
}
