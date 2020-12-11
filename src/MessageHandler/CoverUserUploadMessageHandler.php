<?php

/**
 * @file
 * Upload image service queue processor.
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Event\VendorEvent;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Message\CoverUserUploadMessage;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
use App\Service\VendorService\UserUpload\UserUploadVendorService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class CoverUploadProcessor.
 */
class CoverUserUploadMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $dispatcher;
    private $logger;

    /** @var Vendor $vendor */
    private $vendor;
    private $sourceRepo;

    /**
     * CoverUploadProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $informationLogger
     * @param EventDispatcherInterface $eventDispatcher
     * @param SourceRepository $sourceRepo
     * @param VendorRepository $vendorRepo
     *
     * @throws IllegalVendorServiceException
     * @throws UnknownVendorServiceException
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $informationLogger, EventDispatcherInterface $eventDispatcher, SourceRepository $sourceRepo, VendorRepository $vendorRepo, UserUploadVendorService $userUploadVendorService)
    {
        $this->em = $entityManager;
        $this->logger = $informationLogger;
        $this->dispatcher = $eventDispatcher;

        $this->sourceRepo = $sourceRepo;

        // Load vendor here to ensure that it's only load once.
        $this->vendor = $userUploadVendorService->getVendor();
    }

    /**
     * @param CoverUserUploadMessage $message
     *
     * @throws \Doctrine\ORM\Query\QueryException
     */
    public function __invoke(CoverUserUploadMessage $message)
    {
        $identifier = $message->getIdentifier();

        /** @var Source[] $sources */
        $sources = $this->sourceRepo->findByMatchIdList($message->getIdentifierType(), [$identifier => ''], $this->vendor);

        $event = new VendorEvent(VendorState::UNKNOWN, [$identifier], $message->getIdentifierType(), $this->vendor->getId());

        switch ($message->getOperation()) {
            case VendorState::UPDATE:
            case VendorState::INSERT:
                $event->changeType(VendorState::UPDATE);
                if ($this->createUpdateSource($identifier, $sources, $message)) {
                    $event->changeType(VendorState::INSERT);
                }
                break;

            case VendorState::DELETE:
                $event->changeType(VendorState::DELETE);
                break;
        }

        $this->dispatcher->dispatch($event, $event::NAME);
    }

    /**
     * Create or update existing source entity in the database.
     *
     * @param string $identifier
     *   Material identifier (matchId)
     * @param Source[] $sources
     *   The sources found based on the identifier in the database
     * @param CoverUserUploadMessage $uploadProcessMessage
     *   The process message to build for the event producer
     *
     * @return bool
     *   true on insert and false on update
     */
    private function createUpdateSource(string $identifier, array $sources, CoverUserUploadMessage $uploadProcessMessage): bool
    {
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

        // Make it stick.
        $this->em->flush();
        $this->em->clear(Source::class);

        // Clean up memory (as this class lives in the queue system and may process more than one queue element).
        gc_collect_cycles();

        return $isNew;
    }
}
