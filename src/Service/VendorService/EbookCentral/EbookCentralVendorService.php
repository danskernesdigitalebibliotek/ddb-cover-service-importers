<?php
/**
 * @file
 * Service for updating data from 'eBook Central' xlsx spreadsheet.
 */

namespace App\Service\VendorService\EbookCentral;

use App\Exception\UnknownVendorResourceFormatException;
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
 * Class EbookCentralVendorService.
 */
class EbookCentralVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 2;

    private const VENDOR_ARCHIVE_DIR = 'EbookCentral';
    private const VENDOR_ARCHIVE_NAME = 'cover images title list ddbdk.xlsx';

    private $resourcesDir;

    /**
     * EbookCentralVendorService constructor.
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
            $consecutivelyEmptyRows = 0;

            $isbnArray = [];
            $status = new VendorStatus();

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cellsArray = $row->getCells();
                    if (0 === $totalRows) {
                        if ('PrintIsbn' !== $cellsArray[2]->getValue() || 'EIsbn' !== $cellsArray[3]->getVAlue() || 'http://ebookcentral.proquest.com/covers/Document ID-l.jpg' !== $cellsArray[6]->getValue()) {
                            throw new UnknownVendorResourceFormatException('Unknown columns in xlsx resource file.');
                        }
                    } else {
                        $imageUrl = $cellsArray[6]->getVAlue();
                        if (!empty($imageUrl)) {
                            $printIsbn = $cellsArray[2]->getValue();
                            $eIsbn = $cellsArray[3]->getValue();

                            if (!empty($printIsbn)) {
                                $isbnArray[$printIsbn] = $imageUrl;
                            }
                            if (!empty($eIsbn)) {
                                $isbnArray[$eIsbn] = $imageUrl;
                            }
                        }

                        // Monitor empty row count to terminate loop.
                        if (empty($printIsbn) && empty($eIsbn)) {
                            ++$consecutivelyEmptyRows;
                        } else {
                            $consecutivelyEmptyRows = 0;
                        }
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

                    // Sheet has 1 mil+ rows and the last ~850k are empty. Stop when we get to them.
                    // File also has large gaps of rows withs no ISBNs the first ~150k rows so we can't
                    // just stop at first empty row.
                    //
                    // And yes - import format sucks. Don't mention the war.
                    if ($consecutivelyEmptyRows > 10000) {
                        $this->progressMessage('Seen 10000 empty rows, skipping the rest....');

                        break;
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
