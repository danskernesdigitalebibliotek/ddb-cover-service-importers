<?php
/**
 * @file
 * Service for updating data from 'Saxo' xlsx spreadsheet.
 */

namespace App\Service\VendorService\Saxo;

use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\XLSX\Reader;
use Symfony\Component\Config\FileLocator;

/**
 * Class SaxoVendorService.
 */
class SaxoVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 3;

    private const VENDOR_ARCHIVE_DIR = 'Saxo';
    private const VENDOR_ARCHIVE_NAME = 'Danske bogforsider.xlsx';

    private string $resourcesDir;

    /**
     * SaxoVendorService constructor.
     *
     * @param string $resourcesDir
     *   The application resource dir
     */
    public function __construct(string $resourcesDir)
    {
        $this->resourcesDir = $resourcesDir;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        $status = new VendorStatus();

        try {
            $this->progressStart('Opening sheet: "'.self::VENDOR_ARCHIVE_NAME.'"');

            $reader = $this->getSheetReader();

            $totalRows = 0;
            $isbnArray = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cellsArray = $row->getCells();
                    $isbn = (string) $cellsArray[0]->getValue();

                    if (!empty($isbn)) {
                        $isbnArray[$isbn] = $this->getVendorsImageUrl($isbn);
                    }

                    ++$totalRows;

                    if ($this->limit && $totalRows >= $this->limit) {
                        break;
                    }

                    if (0 === $totalRows % 100) {
                        $this->vendorCoreService->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                        $isbnArray = [];

                        $this->progressMessageFormatted($status);
                        $this->progressAdvance();
                    }
                }
            }

            $this->vendorCoreService->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

            $this->progressFinish();

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
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

        return $vendor->getImageServerURI().'_'.$isbn.'/0x0';
    }

    /**
     * Get a xlsx file reader reference for the import source.
     *
     * @return Reader
     *
     * @throws IOException
     */
    private function getSheetReader(): Reader
    {
        $resourceDirectories = [$this->resourcesDir.'/'.self::VENDOR_ARCHIVE_DIR];

        $fileLocator = new FileLocator($resourceDirectories);
        $filePath = $fileLocator->locate(self::VENDOR_ARCHIVE_NAME);

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($filePath);

        return $reader;
    }
}
