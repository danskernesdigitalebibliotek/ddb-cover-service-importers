<?php

/**
 * @file
 * Search processor
 */

namespace App\Queue;

use App\Entity\Source;
use App\Event\IndexReadyEvent;
use App\Exception\MaterialTypeException;
use App\Exception\PlatformSearchException;
use App\Service\OpenPlatform\SearchService;
use App\Utils\Message\ProcessMessage;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\TopicSubscriberInterface;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Karriere\JsonDecoder\JsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class SearchProcessor.
 */
class SearchProcessor implements Processor, TopicSubscriberInterface
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
     * {@inheritdoc}
     *
     * @throws \App\Exception\PlatformAuthException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        /** @var ProcessMessage $processMessage */
        $processMessage = $jsonDecoder->decode($message->getBody(), ProcessMessage::class);

        // Clean up: find all search that links back to a give source and remove them before sending new index event.
        // This is done even if the search below is a zero-hit.
        if (VendorState::DELETE_AND_UPDATE === $processMessage->getOperation()) {
            $sourceRepos = $this->em->getRepository(Source::class);
            /** @var Source $source */
            $source = $sourceRepos->findOneBy([
                'matchId' => $processMessage->getIdentifier(),
                'matchType' => $processMessage->getIdentifierType(),
                'vendor' => $processMessage->getVendorId(),
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
                    'matchId' => $processMessage->getIdentifier(),
                    'matchType' => $processMessage->getIdentifierType(),
                    'vendor' => $processMessage->getVendorId(),
                ]);

                return self::REJECT;
            }
        }

        try {
            $material = $this->searchService->search($processMessage->getIdentifier(), $processMessage->getIdentifierType());
        } catch (PlatformSearchException $e) {
            $this->statsLogger->error('Search request exception', [
                'service' => 'SearchProcessor',
                'identifier' => $processMessage->getIdentifier(),
                'type' => $processMessage->getIdentifierType(),
                'message' => $e->getMessage(),
            ]);

            return self::REQUEUE;
        } catch (MaterialTypeException $e) {
            $this->statsLogger->error('Unknown material type found', [
                'service' => 'SearchProcessor',
                'message' => $e->getMessage(),
                'identifier' => $processMessage->getIdentifier(),
                'type' => $processMessage->getIdentifierType(),
                'imageId' => $processMessage->getImageId(),
            ]);

            return self::REJECT;
        }

        // Check if this was an zero hit search.
        if ($material->isEmpty()) {
            $this->statsLogger->info('Search zero-hit', [
                'service' => 'SearchProcessor',
                'identifier' => $processMessage->getIdentifier(),
                'type' => $processMessage->getIdentifierType(),
                'imageId' => $processMessage->getImageId(),
            ]);

            return self::REJECT;
        } else {
            $event = new IndexReadyEvent();
            $event->setIs($processMessage->getIdentifier())
                ->setOperation($processMessage->getOperation())
                ->setVendorId($processMessage->getVendorId())
                ->setImageId($processMessage->getImageId())
                ->setMaterial($material);

            $this->dispatcher->dispatch($event::NAME, $event);
        }

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [
            'SearchTopic' => [
                'processorName' => 'SearchProcessor',
                'queueName' => 'SearchQueue',
            ],
        ];
    }
}
