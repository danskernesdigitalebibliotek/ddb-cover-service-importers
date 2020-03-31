<?php

/**
 * @file
 * Upload image service queue processor.
 */

namespace App\Queue;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Event\VendorEvent;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
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
    /** @var Vendor $vendor */
    private $vendor;
    private $sourceRepo;

    /**
     * CoverUploadProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     * @param EventDispatcherInterface $eventDispatcher
     * @param SourceRepository $sourceRepo
     * @param VendorRepository $vendorRepo
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $statsLogger, EventDispatcherInterface $eventDispatcher, SourceRepository $sourceRepo, VendorRepository $vendorRepo)
    {
        $this->em = $entityManager;
        $this->statsLogger = $statsLogger;
        $this->dispatcher = $eventDispatcher;

        $this->sourceRepo = $sourceRepo;

        // Load vendor here to ensure that it's only load once.
        $this->vendor = $vendorRepo->find(self::VENDOR_ID);
    }

    /**
     * {@inheritdoc}
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        /** @var CoverUploadProcessMessage $uploadProcessMessage */
        $uploadProcessMessage = $jsonDecoder->decode($message->getBody(), CoverUploadProcessMessage::class);
        $identifier = $uploadProcessMessage->getIdentifier();

        /** @var Source[] $sources */
        $sources = $this->sourceRepo->findByMatchIdList($uploadProcessMessage->getIdentifierType(), [$identifier => ''], $this->vendor);

        /**
         * @TODO: Add support for delete $uploadProcessMessage->getOperation() === VendorState::DELETE
         */
        $isNew = true;
        if (array_key_exists($identifier, $sources)) {
            $source = $sources[$identifier];
            $source->setMatchType($uploadProcessMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($this->vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($uploadProcessMessage->getImageUrl());
            $isNew = false;
        } else {
            $source = new Source();
            $source->setMatchType($uploadProcessMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($this->vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($uploadProcessMessage->getImageUrl());
            $this->em->persist($source);
        }

        // Make it stick and clean up memory.
        $this->em->flush();
        $this->em->clear(Source::class);
        gc_collect_cycles();

        if ($isNew) {
            $event = new VendorEvent(VendorState::INSERT, [$identifier], $uploadProcessMessage->getIdentifierType(), $this->vendor->getId());
            $this->dispatcher->dispatch($event::NAME, $event);
        } else {
            $event = new VendorEvent(VendorState::UPDATE, [$identifier], $uploadProcessMessage->getIdentifierType(), $this->vendor->getId());
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
