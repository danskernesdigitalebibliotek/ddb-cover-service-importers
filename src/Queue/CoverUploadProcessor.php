<?php

/**
 * @file
 * Upload image service queue processor.
 */

namespace App\Queue;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Event\VendorEvent;
use App\Utils\Message\CoverUploadProcessMessage;
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
 * Class CoverUploadProcessor.
 */
class CoverUploadProcessor implements Processor, TopicSubscriberInterface
{
    private $em;
    private $dispatcher;
    private $statsLogger;

    private const VENDOR_ID = 12;

    /**
     * CoverUploadProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $statsLogger, EventDispatcherInterface $eventDispatcher)
    {
        $this->em = $entityManager;
        $this->statsLogger = $statsLogger;
        $this->dispatcher = $eventDispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        /** @var CoverUploadProcessMessage $uploadProcessMessage */
        $uploadProcessMessage = $jsonDecoder->decode($message->getBody(), CoverUploadProcessMessage::class);
        $identifier = $uploadProcessMessage->getIdentifierType();

        // @TODO: Could this be optimized to only be loaded once!
        $vendorRepo = $this->em->getRepository(Vendor::class);
        /** @var Vendor $vendor */
        $vendor = $vendorRepo->find(self::VENDOR_ID);

        $sourceRepo = $this->em->getRepository(Source::class);
        /** @var Source[] $sources */
        $sources = $sourceRepo->findByMatchIdList($uploadProcessMessage->getIdentifierType(), [$identifier => ''], $vendor);

        /**
         * @TODO: Add support for delete $uploadProcessMessage->getOperation() === VendorState::DELETE
         */
        $isNew = true;
        if (array_key_exists($identifier, $sources)) {
            $source = $sources[$identifier];
            $source->setMatchType($uploadProcessMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($uploadProcessMessage->getImageUrl());
            $isNew = false;
        } else {
            $source = new Source();
            $source->setMatchType($uploadProcessMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($uploadProcessMessage->getImageUrl());
            $this->em->persist($source);
        }

        // Make it stick and clean up memory.
        $this->em->flush();
        $this->em->clear();
        gc_collect_cycles();

        if ($isNew) {
            $event = new VendorEvent(VendorState::INSERT, [$identifier], $uploadProcessMessage->getIdentifierType(), $vendor);
            $this->dispatcher->dispatch($event::NAME, $event);
        } else {
            $event = new VendorEvent(VendorState::UPDATE, [$identifier], $uploadProcessMessage->getIdentifierType(), $vendor);
            $this->dispatcher->dispatch($event::NAME, $event);
        }

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics(): array
    {
        return [
            'UploadImageTopic' => [
                'processorName' => 'UploadImageProcessor',
                'queueName' => 'CoverStoreQueue',
            ],
        ];
    }
}
