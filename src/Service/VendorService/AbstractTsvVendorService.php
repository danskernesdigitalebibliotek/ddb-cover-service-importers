<?php
/**
 * @file
 * Abstract vendor for tsv file imports.
 */

namespace App\Service\VendorService;

use App\Exception\UnknownVendorResourceFormatException;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorStatus;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\CSV\Reader;
use Symfony\Component\Config\FileLocator;

/**
 * Class AbstractTsvVendorService.
 */
abstract class AbstractTsvVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected $vendorArchiveDir = 'AbstractTsvVendor';
    protected $vendorArchiveName = 'covers.tsv';

    private $resourcesDir;

    private $tsvBatchSize = 100;

    /**
     * AbstractTsvVendorService constructor.
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
            $this->progressStart('Opening tsv: "'.$this->vendorArchiveName.'"');

            $reader = $this->getSheetReader();

            $totalRows = 0;
            $pidArray = [];
            $status = new VendorStatus();

            foreach ($reader->getSheetIterator() as $sheet) {
                $fields = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $cellsArray = $row->getCells();
                    if (0 === $totalRows) {
                        $fields = $this->findCellName($cellsArray);
                        // First row in the tsv file contains the headers.
                        if (!array_key_exists('faust', $fields) || !array_key_exists('ppid', $fields) || !array_key_exists('url', $fields)) {
                            throw new UnknownVendorResourceFormatException('Unknown columns in tsv resource file.');
                        }
                    } else {
                        if (!empty($fields)) {
                            $basisPid = $cellsArray[$fields['ppid']]->getValue();
                            $imageUrl = $cellsArray[$fields['url']]->getValue();
                            if (!empty($basisPid) && !empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                $pidArray[$basisPid] = $imageUrl;
                            }
                        } else {
                            throw new UnknownVendorResourceFormatException('Header row was not found.');
                        }
                    }

                    ++$totalRows;

                    if ($this->limit && $totalRows >= $this->limit) {
                        break;
                    }

                    if (0 === $totalRows % $this->tsvBatchSize) {
                        $this->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID);

                        $pidArray = [];

                        $this->progressMessageFormatted($status);
                        $this->progressAdvance();
                    }
                }
            }

            $this->updateOrInsertMaterials($status, $pidArray, IdentifierType::PID);
            $this->progressFinish();

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Get a tsv file reader reference for the import source.
     *
     * @return Reader
     *
     * @throws IOException
     */
    private function getSheetReader(): Reader
    {
        $resourceDirectories = [$this->resourcesDir.'/'.$this->vendorArchiveDir];

        $fileLocator = new FileLocator($resourceDirectories);
        $filePath = $fileLocator->locate($this->vendorArchiveName, null, true);

        $reader = ReaderEntityFactory::createCSVReader();
        $reader->setFieldDelimiter("\t");
        $reader->open($filePath);

        return $reader;
    }

    /**
     * Helper function to get cell names.
     *
     * @param array $cellsArray
     *   The first row from the tsv file
     *
     * @return array
     *   Keys are cell names and values are cell numbers
     */
    private function findCellName(array $cellsArray)
    {
        $ret = [];

        if (empty($ret)) {
            $fields = array_map(function ($cell) {
                return mb_strtolower($cell->getValue());
            }, $cellsArray);
            $ret = array_flip($fields);
        }

        return $ret;
    }
}
