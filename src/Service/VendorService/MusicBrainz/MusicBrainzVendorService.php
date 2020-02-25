<?php
/**
 * @file
 * Service for updating data from 'Musicbrainz' tsv file.
 */

namespace App\Service\VendorService\MusicBrainz;

use App\Exception\UnknownVendorResourceFormatException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;
use Box\Spout\Common\Exception\IOException;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\ReaderFactory;
use Box\Spout\Reader\CSV\Reader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class MusicBrainzVendorService.
 */
class MusicBrainzVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 9;

    private const VENDOR_ARCHIVE_DIR = 'MusicBrainz';
    private const VENDOR_ARCHIVE_NAME = 'mb.covers.tsv';

    private $resourcesDir;

    /**
     * SaxoVendorService constructor.
     *
     * @param eventDispatcherInterface $eventDispatcher
     *   Dispatcher to trigger async jobs on import
     * @param entityManagerInterface $entityManager
     *   Doctrine entity manager
     * @param loggerInterface $statsLogger
     *   Logger object to send stats to ES
     * @param string $resourcesDir
     *   The application resource dir
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $statsLogger, string $resourcesDir)
    {
        parent::__construct($eventDispatcher, $entityManager, $statsLogger);

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
            $this->progressStart('Opening tsv: "'.self::VENDOR_ARCHIVE_NAME.'"');

            $reader = $this->getSheetReader();

            $totalRows = 0;
            $pidArray = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cellsArray = $row->getCells();
                    if (0 === $totalRows) {
                        if ('faust' !== $cellsArray[0]->getValue() || 'ppid' !== $cellsArray[1]->getVAlue() || 'mb_coverurl' !== $cellsArray[7]->getValue()) {
                            throw new UnknownVendorResourceFormatException('Unknown columns in tsv resource file.');
                        }
                    } else {
                        $basisPid = $cellsArray[1]->getVAlue();
                        $imageUrl = $cellsArray[7]->getVAlue();
                        if (!empty($basisPid && !empty($imageUrl))) {
                            $pidArray[$basisPid] = $imageUrl;
                        }
                    }

                    ++$totalRows;

                    if ($this->limit && $totalRows >= $this->limit) {
                        break;
                    }

                    if (0 === $totalRows % 100) {
                        $this->updateOrInsertMaterials($pidArray);

                        $pidArray = [];

                        $this->progressMessageFormatted($this->totalUpdated, $this->totalInserted, $totalRows);
                        $this->progressAdvance();
                    }
                }
            }

            $this->updateOrInsertMaterials($pidArray);
            $this->logStatistics();
            $this->progressFinish();

            return VendorImportResultMessage::success($this->totalIsIdentifiers, $this->totalUpdated, $this->totalInserted);
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
        $resourceDirectories = [$this->resourcesDir.'/'.self::VENDOR_ARCHIVE_DIR];

        $fileLocator = new FileLocator($resourceDirectories);
        $filePath = $fileLocator->locate(self::VENDOR_ARCHIVE_NAME, null, true);

        $reader = ReaderEntityFactory::createCSVReader();
        $reader->setFieldDelimiter("\t");
        $reader->open($filePath);

        return $reader;
    }
}
