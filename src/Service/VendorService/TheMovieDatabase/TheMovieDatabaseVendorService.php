<?php

/**
 * @file
 * Use a library's data well access to get movies.
 */

namespace App\Service\VendorService\TheMovieDatabase;

use App\Entity\Source;
use App\Event\VendorEvent;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class DataWellVendorService.
 */
class TheMovieDatabaseVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 6;

    private $dataWell;
    private $api;
    private $queries = [
        'phrase.type="blu-ray" and facet.typeCategory="film"',
        'phrase.type="dvd" and facet.typeCategory="film"',
    ];

    /**
     * TheMovieDatabaseVendorService constructor.
     *
     * @param EventDispatcherInterface      $eventDispatcher
     *   The event dispatcher
     * @param EntityManagerInterface        $entityManager
     *   The entity manager
     * @param LoggerInterface               $informationLogger
     *   The stats logger
     * @param theMovieDatabaseSearchService $dataWell
     *   The search service
     * @param TheMovieDatabaseApiService    $api
     *   The movie api service
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, EntityManagerInterface $entityManager, LoggerInterface $informationLogger, TheMovieDatabaseSearchService $dataWell, TheMovieDatabaseApiService $api)
    {
        parent::__construct($eventDispatcher, $entityManager, $informationLogger);

        $this->dataWell = $dataWell;
        $this->api = $api;
    }

    /**
     * @{@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
        }

        // We're lazy loading the config to avoid errors from missing config values on dependency injection
        $this->loadConfig();

        $this->progressStart('Search data well for movies');

        $offset = 1;
        $queriesIndex = 0;
        try {
            // @TODO: Change this to use foreach?
            while (count($this->queries) > $queriesIndex) {
                do {
                    // Search the data well for materials.
                    $query = $this->queries[$queriesIndex];
                    [$resultArray, $more, $offset] = $this->dataWell->search($query, $offset);

                    // This is a hack to get the 'processBatch' working below.
                    $pidArray = array_map(
                        function ($value) {
                            return '';
                        },
                        $resultArray
                    );

                    $batchSize = \count($pidArray);

                    // @TODO: this should be handled in updateOrInsertMaterials, which should take which event and job
                    //        it should call. Default is now CoverStore (upload image), which we do not know yet.
                    $sourceRepo = $this->em->getRepository(Source::class);
                    $batchOffset = 0;
                    while ($batchOffset < $batchSize) {
                        $batch = \array_slice($pidArray, $batchOffset, self::BATCH_SIZE, true);
                        [$updatedIdentifiers, $insertedIdentifiers] = $this->processBatch($batch, $sourceRepo, IdentifierType::PID);

                        $this->postProcess($updatedIdentifiers, $resultArray);
                        $this->postProcess($insertedIdentifiers, $resultArray);

                        $batchOffset += $batchSize;
                    }

                    // @TODO: How to handle multiple queries with progress bar.
                    $this->progressMessageFormatted($this->totalUpdated, $this->totalInserted, $this->totalIsIdentifiers);
                    $this->progressAdvance();

                    if ($this->limit && $this->totalIsIdentifiers >= $this->limit) {
                        $more = false;
                    }
                } while ($more);

                ++$queriesIndex;
            }

            $this->progressFinish();

            return VendorImportResultMessage::success($this->totalIsIdentifiers, $this->totalUpdated, $this->totalInserted, $this->totalDeleted);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config fro service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    private function loadConfig(): void
    {
        // Set the service access configuration from the vendor.
        $this->dataWell->setSearchUrl($this->getVendor()->getDataServerURI());
        $this->dataWell->setUser($this->getVendor()->getDataServerUser());
        $this->dataWell->setPassword($this->getVendor()->getDataServerPassword());
    }

    /**
     * Lookup post urls post normal batch processing.
     *
     * @param array $pids
     *   The source table pids
     * @param array $searchResults
     *   The datawell search result
     *
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     * @throws GuzzleException
     */
    private function postProcess(array $pids, array $searchResults)
    {
        $sourceRepo = $this->em->getRepository(Source::class);

        foreach ($pids as $pid) {
            $metadata = $searchResults[$pid];

            // Find source in database.
            $source = $sourceRepo->findOneBy([
                'matchId' => $pid,
                'matchType' => IdentifierType::PID,
                'vendor' => $this->getVendor(),
            ]);

            if (null !== $source) {
                // Get poster url.
                $posterUrl = $this->api->searchPosterUrl($metadata['title'], $metadata['originalYear'], $metadata['director']);

                if (null !== $posterUrl) {
                    // Set poster url of source.
                    $source->setOriginalFile($posterUrl);
                    $this->em->flush();

                    // Create vendor event.
                    $event = new VendorEvent(VendorState::INSERT, [$source->getMatchId()], $source->getMatchType(), $source->getVendor()->getId());
                    $this->dispatcher->dispatch($event::NAME, $event);
                }
            }
        }
    }
}
