<?php

/**
 * @file
 * Search message handler
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformSearchException;
use App\Message\IndexMessage;
use App\Message\SearchMessage;
use App\Service\OpenPlatform\SearchService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class SearchMessageHandler.
 */
class SearchMessageHandler implements MessageHandlerInterface
{
    /**
     * SearchProcessor constructor.
     *
     * @param EntityManagerInterface $em
     * @param MessageBusInterface $bus
     * @param LoggerInterface $logger
     * @param SearchService $searchService
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly SearchService $searchService
    ) {
    }

    /**
     * @param SearchMessage $message
     *
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
            $indexMessage = new IndexMessage();
            $indexMessage->setIdentifier($message->getIdentifier())
                ->setOperation($message->getOperation())
                ->setVendorId($message->getVendorId())
                ->setImageId($message->getImageId())
                ->setMaterial($material);

            $this->bus->dispatch($indexMessage);
        }

        // Free memory.
        $this->em->clear();
    }
}
