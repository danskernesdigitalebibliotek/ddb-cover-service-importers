<?php

/**
 * @file
 */

namespace App\EventSubscriber;

use App\Event\VendorEvent;
use App\Message\DeleteMessage;
use App\Utils\Message\ProcessMessage;
use App\Utils\Types\VendorState;
use Enqueue\Client\ProducerInterface;
use Enqueue\Util\JSON;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class VendorEventSubscriber.
 */
class VendorEventSubscriber implements EventSubscriberInterface
{
    private $producer;
    private $bus;

    /**
     * VendorEventSubscriber constructor.
     *
     * @param producerInterface $producer
     *   Queue producer to send messages (jobs)
     */
    public function __construct(ProducerInterface $producer, MessageBusInterface $bus)
    {
        $this->producer = $producer;
        $this->bus = $bus;
    }

    /**
     * {@inheritdoc}
     *
     * Defines the events that we subscribes to.
     */
    public static function getSubscribedEvents()
    {
        return [
            VendorEvent::NAME => 'onVendorIndexEvent',
        ];
    }

    /**
     * Event handler.
     *
     * @param VendorEvent $event
     */
    public function onVendorIndexEvent(VendorEvent $event)
    {
        $identifiers = $event->getIdentifiers();
        $identifierType = $event->getIdentifierType();
        $type = $event->getType();
        $vendorId = $event->getVendorId();

        switch ($type) {
            case VendorState::INSERT:
            case VendorState::UPDATE:
                $message = new ProcessMessage();
                $jobName = 'VendorImageTopic';
                break;

            case VendorState::DELETE:
                $message = new DeleteMessage();
                break;

            default:
                // @TODO: Handle unknown vendor event type.
                return;
        }

        foreach ($identifiers as $identifier) {

            $message->setOperation($type)
                ->setIdentifier($identifier)
                ->setVendorId($vendorId)
                ->setIdentifierType($identifierType);

            $this->bus->dispatch($message);
        }
    }
}
