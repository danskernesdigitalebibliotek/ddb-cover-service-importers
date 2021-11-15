<?php

/**
 * @file
 * Search message handler
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Event\IndexReadyEvent;
use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformAuthException;
use App\Exception\OpenPlatformSearchException;
use App\Message\SearchMessage;
use App\Service\OpenPlatform\SearchService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class SearchMessageHandler.
 */
class SearchMessageHandler implements MessageHandlerInterface
{
    private EntityManagerInterface $em;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;
    private SearchService $searchService;

    /**
     * SearchProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface $informationLogger
     * @param SearchService $searchService
     */
    public function __construct(EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher, LoggerInterface $informationLogger, SearchService $searchService)
    {
        $this->em = $entityManager;
        $this->dispatcher = $eventDispatcher;
        $this->logger = $informationLogger;
        $this->searchService = $searchService;
    }

    /**
     * @param SearchMessage $message
     *
     * @throws OpenPlatformAuthException
     * @throws OpenPlatformSearchException
     */
    public function __invoke(SearchMessage $message)
    {
        // Clean up: find all search that links back to a give source and remove them before sending new index event.
        // This is done even if the search below is a zero-hit.
        if (VendorState::DELETE_AND_UPDATE === $message->getOperation()) {
            $sourceRepos = $this->em->getRepository(Source::class);
            /** @var Source $source */
            $source = $sourceRepos->findOneBy([
                'matchId' => $message->getIdentifier(),
                'matchType' => $message->getIdentifierType(),
                'vendor' => $message->getVendorId(),
            ]);
            if (!is_null($source)) {
                $searches = $source->getSearches();
                foreach ($searches as $search) {
                    $this->em->remove($search);
                }
                $this->em->flush();
            } else {
                $this->logger->error('Unknown material type found', [
                    'service' => 'SearchProcessor',
                    'message' => 'Doing reindex source was null, hence the database has changed',
                    'matchId' => $message->getIdentifier(),
                    'matchType' => $message->getIdentifierType(),
                    'vendor' => $message->getVendorId(),
                ]);

                throw new UnrecoverableMessageHandlingException('Unknown material type found');
            }
        }

        try {
            $material = $this->searchService->search($message->getIdentifier(), $message->getIdentifierType(), !$message->useSearchCache());
        } catch (OpenPlatformSearchException $e) {
            $this->logger->error('Search request exception', [
                'service' => 'SearchProcessor',
                'identifier' => $message->getIdentifier(),
                'type' => $message->getIdentifierType(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (MaterialTypeException $e) {
            $this->logger->error('Unknown material type found', [
                'service' => 'SearchProcessor',
                'message' => $e->getMessage(),
                'identifier' => $message->getIdentifier(),
                'type' => $message->getIdentifierType(),
                'imageId' => $message->getImageId(),
            ]);

            throw new UnrecoverableMessageHandlingException('Unknown material type found');
        }

        // Check if this was a zero hit search.
        if ($material->isEmpty()) {
            $this->logger->info('Search zero-hit', [
                'service' => 'SearchProcessor',
                'identifier' => $message->getIdentifier(),
                'type' => $message->getIdentifierType(),
                'imageId' => $message->getImageId(),
            ]);
        } else {
            $event = new IndexReadyEvent();
            $event->setIs($message->getIdentifier())
                ->setOperation($message->getOperation())
                ->setVendorId($message->getVendorId())
                ->setImageId($message->getImageId())
                ->setMaterial($material);

            $this->dispatcher->dispatch($event, $event::NAME);
        }
    }
}
