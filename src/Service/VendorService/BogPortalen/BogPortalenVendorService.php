<?php
/**
 * @file
 * Service for updating data from 'Bogportalen'.
 */

namespace App\Service\VendorService\BogPortalen;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Class BogPortalenVendorService.
 */
class BogPortalenVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 1;
    private const VENDOR_ARCHIVE_NAMES = ['BOP-ProductAll.zip', 'BOP-ProductAll-EXT.zip', 'BOP-Actual.zip', 'BOP-Actual-EXT.zip'];

    private $local;
    private $ftp;

    /**
     * BogPortalenVendorService constructor.
     *
     * @param eventDispatcherInterface $eventDispatcher
     *   Dispatcher to trigger async jobs on import
     * @param filesystem $local
     *   Flysystem adapter for local filesystem
     * @param filesystem $ftp
     *   Flysystem adapter for remote ftp server
     * @param entityManagerInterface $entityManager
     *   Doctrine entity manager
     * @param loggerInterface $statsLogger
     *   Logger object to send stats to ES
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, Filesystem $local, Filesystem $ftp, EntityManagerInterface $entityManager, LoggerInterface $statsLogger)
    {
        parent::__construct($eventDispatcher, $entityManager, $statsLogger);

        $this->local = $local;
        $this->ftp = $ftp;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        // We're lazy loading the config to avoid errors from missing config values on dependency injection
        $this->loadConfig();

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
                    $this->updateOrInsertMaterials($isbnImageUrlArray, IdentifierType::ISBN);

                    $this->progressMessageFormatted($this->totalUpdated, $this->totalInserted, $this->totalIsIdentifiers);
                    $this->progressAdvance();

                    $offset += self::BATCH_SIZE;
                }

                $this->local->delete($archive);
            } catch (InvalidArgumentException $e) {
                return VendorImportResultMessage::error($e->getMessage());
            } catch (\Exception $e) {
                return VendorImportResultMessage::error($e->getMessage());
            }
        }

        $this->logStatistics();

        $this->progressFinish();

        return VendorImportResultMessage::success($this->totalIsIdentifiers, $this->totalUpdated, $this->totalInserted, $this->totalDeleted);
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    private function loadConfig(): void
    {
        // Set FTP adapter configuration.
        $adapter = $this->ftp->getAdapter();
        $adapter->setUsername($this->getVendor()->getDataServerUser());
        $adapter->setPassword($this->getVendor()->getDataServerPassword());
        $adapter->setHost($this->getVendor()->getDataServerURI());
    }

    /**
     * Build array of image urls keyed by isbn.
     *
     * @param array $isbnList
     *
     * @return array
     *
     * @throws \Exception
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
     * @throws IllegalVendorServiceException
     */
    private function getVendorsImageUrl(string $isbn): string
    {
        return $this->getVendor()->getImageServerURI().$isbn.'.jpg';
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
        return $this->local->put($archive, $this->ftp->read($archive));
    }

    /**
     * Get list of files in ZIP archive.
     *
     * @param $path
     *   The path of the archive in the local filesystem
     *
     * @return array
     *   List of filenames
     *
     * @throws FileNotFoundException
     */
    private function listZipContents($path): array
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
     * @return array
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
