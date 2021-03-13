<?php

/**
 * @file
 * Use a library's data well access to get movies.
 */

namespace App\Service\VendorService\TheMovieDatabase;

use App\Entity\Source;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use App\Utils\Types\VendorStatus;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class DataWellVendorService.
 */
class TheMovieDatabaseVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 6;

    private $vendorCoreService;
    private $em;
    private $bus;
    private $dataWell;
    private $api;
    private $queries = [
        'phrase.type="blu-ray" and facet.typeCategory="film"',
        'phrase.type="dvd" and facet.typeCategory="film"',
    ];

    /**
     * TheMovieDatabaseVendorService constructor.
     *
     * @param EntityManagerInterface $em
     *   Database entity manager
     * @param MessageBusInterface $bus
     *   Message bus for the queue system
     * @param theMovieDatabaseSearchService $dataWell
     *   The search service
     * @param TheMovieDatabaseApiService $api
     *   The movie api service
     */
    public function __construct(EntityManagerInterface $em, MessageBusInterface $bus, TheMovieDatabaseSearchService $dataWell, TheMovieDatabaseApiService $api)
    {
        $this->em = $em;
        $this->bus = $bus;
        $this->dataWell = $dataWell;
        $this->api = $api;
    }

    /**
     * @{@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        // We're lazy loading the config to avoid errors from missing config values on dependency injection
        $this->loadConfig();

        $status = new VendorStatus();

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

                    /** @var SourceRepository $sourceRepo */
                    $sourceRepo = $this->em->getRepository(Source::class);
                    $batchOffset = 0;
                    while ($batchOffset < $batchSize) {
                        $batch = \array_slice($pidArray, $batchOffset, self::BATCH_SIZE, true);
                        [$updatedIdentifiers, $insertedIdentifiers] = $this->vendorCoreService->processBatch($batch, $sourceRepo, IdentifierType::PID, $this->getVendorId(), $this->withUpdates);

                        $this->postProcess($updatedIdentifiers, $resultArray);
                        $this->postProcess($insertedIdentifiers, $resultArray);

                        // Update status.
                        $status->addUpdated(count($updatedIdentifiers));
                        $status->addInserted(count($insertedIdentifiers));
                        $status->addRecords(count($batch));

                        $batchOffset += $batchSize;
                    }

                    // @TODO: How to handle multiple queries with progress bar.
                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();

                    if ($this->limit && $status->records >= $this->limit) {
                        $more = false;
                    }
                } while ($more);

                ++$queriesIndex;
            }

            $this->progressFinish();

            $this->vendorCoreService->releaseLock($this->getVendorId());

            return VendorImportResultMessage::success($status);
        } catch (\Exception $exception) {
            return VendorImportResultMessage::error($exception->getMessage());
        }
    }

    /**
     * Set config fro service from DB vendor object.
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        // Set the service access configuration from the vendor.
        $this->dataWell->setSearchUrl($vendor->getDataServerURI());
        $this->dataWell->setUser($vendor->getDataServerUser());
        $this->dataWell->setPassword($vendor->getDataServerPassword());
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
        /** @var SourceRepository $sourceRepo */
        $sourceRepo = $this->em->getRepository(Source::class);

        foreach ($pids as $pid) {
            $metadata = $searchResults[$pid];

            // Find source in database.
            $source = $sourceRepo->findOneBy([
                'matchId' => $pid,
                'matchType' => IdentifierType::PID,
                'vendor' => $this->vendorCoreService->getVendor($this->getVendorId()),
            ]);

            if (null !== $source) {
                // Get poster url.
                $posterUrl = $this->api->searchPosterUrl($metadata['title'], $metadata['originalYear'], $metadata['director']);

                if (null !== $posterUrl) {
                    // Set poster url of source.
                    $source->setOriginalFile($posterUrl);
                    $this->em->flush();

                    // Create vendor event.
                    $message = new VendorImageMessage();
                    $message->setOperation(VendorState::INSERT)
                        ->setIdentifier($source->getMatchId())
                        ->setVendorId($source->getVendor()->getId())
                        ->setIdentifierType($source->getMatchType());
                    $this->bus->dispatch($message);
                }
            }
        }
    }
}
