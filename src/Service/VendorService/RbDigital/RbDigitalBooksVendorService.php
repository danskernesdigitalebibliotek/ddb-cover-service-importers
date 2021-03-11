<?php
/**
 * @file
 * Service for updating book covers from 'RB Digital'.
 */

namespace App\Service\VendorService\RbDigital;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\RbDigital\DataConverter\RbDigitalBooksPublicUrlConverter;
use App\Service\VendorService\VendorCoreService;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Psr\Cache\InvalidArgumentException;
use Scriptotek\Marc\Collection;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Class RbDigitalBooksVendorService.
 */
class RbDigitalBooksVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 7;
    public const LOCAL_BATCH_SIZE = 10;

    // List of directories with book records
    private const VENDOR_ARCHIVES_DIRECTORIES = [
        'Recorded Books eAudio World-Wide Library Subscription',
        'Recorded Books eBook Classics Collection',
    ];

    private $vendorCoreService;
    private $local;
    private $ftp;
    private $cache;

    /**
     * RbDigitalVendorService constructor.
     *
     * @param vendorCoreService $vendorCoreService
     *   Service with shared vendor functions
     * @param Filesystem $local
     *   Flysystem adapter for local filesystem
     * @param Filesystem $ftp
     *   Flysystem adapter for remote ftp server
     * @param AdapterInterface $cache
     *   Cache adapter for the application
     */
    public function __construct(VendorCoreService $vendorCoreService, Filesystem $local, Filesystem $ftp, AdapterInterface $cache)
    {
        $this->vendorCoreService = $vendorCoreService;
        $this->local = $local;
        $this->ftp = $ftp;
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     *
     * Note: this is not placed in the vendor service traits as it can not have const.
     */
    public function getVendorId(): int
    {
        return self::VENDOR_ID;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorName(): string
    {
        return $this->vendorCoreService->getVendorName($this->getVendorId());
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId())) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        // We're lazy loading the config to avoid errors from missing config values on dependency injection
        $this->loadConfig();

        $status = new VendorStatus();

        $mrcFileNames = [];
        foreach (self::VENDOR_ARCHIVES_DIRECTORIES as $directory) {
            foreach ($this->ftp->listContents($directory) as $content) {
                $mrcFileNames[] = $content['path'];
            }
        }

        $this->progressStart('Checking for updated archives');

        foreach ($mrcFileNames as $mrcFileName) {
            $this->progressMessage('Checking for updated archive: "'.$mrcFileName.'"');
            try {
                if ($this->archiveHasUpdate($mrcFileName)) {
                    $this->progressMessage('New archive found, Downloading....');
                    $this->progressAdvance();

                    $this->updateArchive($mrcFileName);
                }

                $this->progressMessage('Getting records from archive....');
                $this->progressAdvance();

                $count = 0;
                $isbnImageUrlArray = [];
                $localArchivePath = $this->local->getAdapter()->getPathPrefix().$mrcFileName;
                $collection = Collection::fromFile($localArchivePath);

                foreach ($collection as $record) {
                    // Query for all subfield 'u' in all field '856' that also has subfield '3' (Image)
                    $imageUrl = $record->query('856$u{?856$3}')->text();
                    $isbns = $record->isbns;
                    foreach ($isbns as $isbn) {
                        $isbnImageUrlArray[$isbn->getContents()] = $imageUrl;
                        ++$count;

                        if (0 === $count % self::LOCAL_BATCH_SIZE) {
                            RbDigitalBooksPublicUrlConverter::convertArrayValues($isbnImageUrlArray);
                            $this->vendorCoreService->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdates, $this->withoutQueue, self::LOCAL_BATCH_SIZE);
                            $isbnImageUrlArray = [];

                            $this->progressMessageFormatted($status);
                            $this->progressAdvance();
                        }
                    }

                    if ($this->limit && $count >= $this->limit) {
                        break;
                    }
                }

                RbDigitalBooksPublicUrlConverter::convertArrayValues($isbnImageUrlArray);
                $this->vendorCoreService->updateOrInsertMaterials($status, $isbnImageUrlArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdates, $this->withoutQueue, self::LOCAL_BATCH_SIZE);
                $isbnImageUrlArray = [];

                $this->progressMessageFormatted($status);
                $this->progressAdvance();
            } catch (\Exception $exception) {
                return VendorImportResultMessage::error($exception->getMessage());
            }
        }

        $this->progressFinish();

        return VendorImportResultMessage::success($status);
    }

    /**
     * Update local copy of vendors archive.
     *
     * @param string $mrcFileName
     *   The path and name of the records file to update
     *
     * @return bool
     *
     * @throws FileNotFoundException
     * @throws InvalidArgumentException
     * @throws IllegalVendorServiceException
     */
    private function updateArchive(string $mrcFileName): bool
    {
        $remoteModifiedAt = $this->ftp->getTimestamp($mrcFileName);
        $remoteModifiedAtCache = $this->cache->getItem($this->getCacheKey($mrcFileName));
        $remoteModifiedAtCache->set($remoteModifiedAt);
        $remoteModifiedAtCache->expiresAfter(24 * 60 * 60);

        $this->cache->save($remoteModifiedAtCache);

        // @TODO Error handling for missing archive
        return $this->local->put($mrcFileName, $this->ftp->read($mrcFileName));
    }

    /**
     * Check if vendors archive has update.
     *
     * @param string $mrcFileName
     *   The path and name of the records file to check for update to
     *
     * @return bool
     *
     * @throws FileNotFoundException
     * @throws InvalidArgumentException
     * @throws IllegalVendorServiceException
     */
    private function archiveHasUpdate(string $mrcFileName): bool
    {
        $update = true;

        if ($this->local->has($mrcFileName)) {
            $remoteModifiedAtCache = $this->cache->getItem($this->getCacheKey($mrcFileName));

            if ($remoteModifiedAtCache->isHit()) {
                $remote = $this->ftp->getTimestamp($mrcFileName);
                $update = $remote > $remoteModifiedAtCache->get();
            }
        }

        return $update;
    }

    /**
     * Get cache key for the given filename.
     *
     * @param string $mrcFileName
     *   The filename to get a cache key for
     *
     * @return string
     *
     * @throws IllegalVendorServiceException
     */
    private function getCacheKey(string $mrcFileName): string
    {
        $hash = md5($mrcFileName);

        return 'app.vendor.'.$this->getVendorId().$hash.'.remoteModifiedAt';
    }

    /**
     * Set config for service from DB vendor object.
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
}
