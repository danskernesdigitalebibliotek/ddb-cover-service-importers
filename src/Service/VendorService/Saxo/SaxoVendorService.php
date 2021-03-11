<?php
/**
 * @file
 * Service for updating data from 'Saxo' xlsx spreadsheet.
 */

namespace App\Service\VendorService\Saxo;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorCoreService;
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
class SaxoVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 3;

    private const VENDOR_ARCHIVE_DIR = 'Saxo';
    private const VENDOR_ARCHIVE_NAME = 'Danske bogforsider.xlsx';

    private $resourcesDir;

    /**
     * SaxoVendorService constructor.
     *
     * @param vendorCoreService $vendorCoreService
     *   Service with shared vendor functions
     * @param string $resourcesDir
     *   The application resource dir
     */
    public function __construct(VendorCoreService $vendorCoreService, string $resourcesDir)
    {
        parent::__construct($vendorCoreService);

        $this->resourcesDir = $resourcesDir;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        try {
            $this->progressStart('Opening sheet: "'.self::VENDOR_ARCHIVE_NAME.'"');

            $reader = $this->getSheetReader();

            $totalRows = 0;
            $isbnArray = [];
            $status = new VendorStatus();

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
                        $this->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN);

                        $isbnArray = [];

                        $this->progressMessageFormatted($status);
                        $this->progressAdvance();
                    }
                }
            }

            $this->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN);
            $this->progressFinish();

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
     * @throws IllegalVendorServiceException
     */
    private function getVendorsImageUrl(string $isbn): string
    {
        return $this->getVendor()->getImageServerURI().'_'.$isbn.'/0x0';
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
        $filePath = $fileLocator->locate(self::VENDOR_ARCHIVE_NAME, null, true);

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($filePath);

        return $reader;
    }
}
