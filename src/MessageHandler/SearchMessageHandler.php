<?php

/**
 * @file
 * Search queue handler
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Event\IndexReadyEvent;
use App\Exception\MaterialTypeException;
use App\Exception\PlatformSearchException;
use App\Message\SearchMessage;
use App\Service\OpenPlatform\SearchService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class SearchProcessor.
 */
class SearchMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $dispatcher;
    private $statsLogger;
    private $searchService;

    /**
     * SearchProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface $statsLogger
     * @param SearchService $searchService
     */
    public function __construct(EntityManagerInterface $entityManager, EventDispatcherInterface $eventDispatcher, LoggerInterface $statsLogger, SearchService $searchService)
    {
        $this->em = $entityManager;
        $this->dispatcher = $eventDispatcher;
        $this->statsLogger = $statsLogger;
        $this->searchService = $searchService;
    }

    /**
     * @param SearchMessage $message
     * @throws PlatformSearchException
     *
     * @throws \App\Exception\PlatformAuthException
     * @throws \Psr\Cache\InvalidArgumentException
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
                $this->statsLogger->error('Unknown material type found', [
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
        } catch (PlatformSearchException $e) {
            $this->statsLogger->error('Search request exception', [
                'service' => 'SearchProcessor',
                'identifier' => $message->getIdentifier(),
                'type' => $message->getIdentifierType(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (MaterialTypeException $e) {
            $this->statsLogger->error('Unknown material type found', [
                'service' => 'SearchProcessor',
                'message' => $e->getMessage(),
                'identifier' => $message->getIdentifier(),
                'type' => $message->getIdentifierType(),
                'imageId' => $message->getImageId(),
            ]);

            throw new UnrecoverableMessageHandlingException('Unknown material type found');
        }

        // Check if this was an zero hit search.
        if ($material->isEmpty()) {
            $this->statsLogger->info('Search zero-hit', [
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
