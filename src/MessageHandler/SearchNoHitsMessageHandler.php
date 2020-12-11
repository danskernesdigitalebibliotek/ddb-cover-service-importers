<?php

/**
 * @file
 * Handle no-hits queue processing.
 */

namespace App\MessageHandler;

use App\Entity\Search;
use App\Entity\Source;
use App\Event\VendorEvent;
use App\Exception\MaterialTypeException;
use App\Exception\PlatformAuthException;
use App\Exception\PlatformSearchException;
use App\Message\SearchMessage;
use App\Message\SearchNoHitsMessage;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\OpenPlatform\SearchService;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class SearchNoHitsMessageHandler.
 */
class SearchNoHitsMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $coverStore;
    private $bus;
    private $logger;
    private $searchService;
    private $validatorService;
    private $dispatcher;

    const VENDOR = 'Unknown';

    /**
     * SearchNoHitsMessageHandler constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param CoverStoreInterface $coverStore
     * @param MessageBusInterface $bus
     * @param LoggerInterface $informationLogger
     * @param SearchService $searchService
     * @param VendorImageValidatorService $validatorService
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(EntityManagerInterface $entityManager, CoverStoreInterface $coverStore, MessageBusInterface $bus, LoggerInterface $informationLogger, SearchService $searchService, VendorImageValidatorService $validatorService, EventDispatcherInterface $eventDispatcher)
    {
        $this->em = $entityManager;
        $this->coverStore = $coverStore;
        $this->bus = $bus;
        $this->logger = $informationLogger;
        $this->searchService = $searchService;
        $this->validatorService = $validatorService;
        $this->dispatcher = $eventDispatcher;
    }

    /**
     * @param SearchNoHitsMessage $message
     *
     * @throws MaterialTypeException
     * @throws PlatformAuthException
     * @throws PlatformSearchException
     * @throws InvalidArgumentException
     */
    public function __invoke(SearchNoHitsMessage $message)
    {
        $identifier = $message->getIdentifier();

        // If it's a "katalog" identifier, we will try to check if a matching
        // "basic" identifier exits and create the mapping.
        if (strpos($identifier, '-katalog:')) {
            $searchRepos = $this->em->getRepository(Search::class);
            $basicPid = null;

            try {
                // Try to get basic pid.
                $basicPid = Material::translatePidToFaust($identifier);

                // There may exists a race condition when multiple queues are
                // running. To ensure we don't insert duplicates we need to
                // wrap our search/update/insert in a transaction.
                $this->em->getConnection()->beginTransaction();

                try {
                    $search = $searchRepos->findOneByisIdentifier($basicPid);

                    if (!empty($search)) {
                        $newSearch = new Search();
                        $newSearch->setIsType(IdentifierType::PID)
                            ->setIsIdentifier($identifier)
                            ->setSource($search->getSource())
                            ->setImageUrl($search->getImageUrl())
                            ->setImageFormat($search->getImageFormat())
                            ->setWidth($search->getWidth())
                            ->setHeight($search->getHeight());
                        $this->em->persist($newSearch);

                        $this->em->flush();
                        $this->em->getConnection()->commit();

                        // Log that a new record was created.
                        $this->logger->info('Katalog recorded have been generated', [
                            'service' => 'SearchNoHitsProcessor',
                            'message' => 'New katalog search record have been generated',
                            'identifier' => $identifier,
                            'source' => $basicPid,
                        ]);

                        return;
                    }
                } catch (\Exception $exception) {
                    $this->em->getConnection()->rollBack();

                    $this->logger->error('Database exception: '.get_class($exception), [
                        'service' => 'SearchNoHitsProcessor',
                        'message' => $exception->getMessage(),
                        'identifier' => $identifier,
                        'source' => $basicPid,
                    ]);
                }
            } catch (ConnectionException $exception) {
                $this->logger->error('Database Connection Exception', [
                    'service' => 'SearchNoHitsProcessor',
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                    'source' => $basicPid ?: 'unknown',
                ]);
            }
        } else {
            // Try to search the data well and match source entity. This might work as there is an race between when
            // vendors have a given cover and when the material is indexed into the data-well.
            $type = $message->getIdentifierType();
            $material = $this->searchService->search($identifier, $type);
            $sourceRepos = $this->em->getRepository(Source::class);

            foreach ($material->getIdentifiers() as $is) {
                $source = $sourceRepos->findOneByVendorRank($is->getType(), $is->getId());

                // Found matches in source table based on the data well search, so create jobs to re-index the source
                // entities.
                if (false !== $source) {
                    // Also check that the source record has an image from the vendor as not all do.
                    if (!is_null($source->getImage())) {
                        $message = new SearchMessage();
                        $message->setIdentifier($source->getMatchId())
                            ->setOperation(VendorState::UPDATE)
                            ->setIdentifierType($source->getMatchType())
                            ->setVendorId($source->getVendor()->getId())
                            ->setImageId($source->getImage()->getId())
                            ->setUseSearchCache(true);
                        $this->bus->dispatch($message);
                    }

                    // If the source image is null. It might have been made available since we asked the vendor for the
                    // cover.
                    if (is_null($source->getImage()) && !is_null($source->getOriginalFile())) {
                        $item = new VendorImageItem();
                        $item->setOriginalFile($source->getOriginalFile());
                        try {
                            $this->validatorService->validateRemoteImage($item);
                        } catch (GuzzleException $e) {
                            // Just remove this job from the queue, on fetch errors. This will ensure that the job is not
                            // re-queue in infinity loop.
                            throw new UnrecoverableMessageHandlingException('Image fetch error in validation');
                        }

                        if ($item->isFound()) {
                            $event = new VendorEvent(VendorState::UPDATE, [$source->getMatchId()], $source->getMatchType(), $source->getVendor()->getId());
                            $this->dispatcher->dispatch($event, $event::NAME);
                        }
                    }
                }
            }

            return;
        }

        // Log current not handled no hit.
        $this->logger->info('No hit', [
            'service' => 'SearchNoHitsProcessor',
            'message' => 'No hit found and send to auto generate queue',
            'identifier' => $identifier,
        ]);
    }
}
