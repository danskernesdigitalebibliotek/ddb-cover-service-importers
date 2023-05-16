<?php

namespace App\EventSubscriber;

use App\Exception\SearchIndexException;
use App\Service\Indexing\IndexingServiceInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

class ApplicationStartedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IndexingServiceInterface $indexingService
    ) {
    }

    /**
     * Event for worker startup.
     *
     * @throws SearchIndexException
     */
    public function onWorkerStartedEvent(WorkerStartedEvent $event): void
    {
        $this->verifyIndexExists();
    }

    /**
     * Event for console startup.
     *
     * @throws SearchIndexException
     */
    public function onConsoleCommandEvent(ConsoleCommandEvent $event): void
    {
        $this->verifyIndexExists();
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStartedEvent',
            ConsoleCommandEvent::class => 'onConsoleCommandEvent',
        ];
    }

    /**
     * Verify that the indexing service has the required index.
     *
     * @throws SearchIndexException
     */
    private function verifyIndexExists(): void
    {
        if (!$this->indexingService->indexExists()) {
            $this->indexingService->createIndex();
        }
    }
}
