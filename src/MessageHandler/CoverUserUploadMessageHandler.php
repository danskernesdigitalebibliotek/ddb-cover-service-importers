<?php

/**
 * @file
 * Upload image service queue processor.
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Message\CoverUserUploadMessage;
use App\Message\DeleteMessage;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
use App\Service\VendorService\UserUpload\UserUploadVendorService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoverUserUploadMessageHandler.
 */
class CoverUserUploadMessageHandler implements MessageHandlerInterface
{
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;
    private Vendor $vendor;
    private SourceRepository $sourceRepo;

    /**
     * CoverUserUploadMessageHandler constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param MessageBusInterface $bus
     * @param SourceRepository $sourceRepo
     * @param UserUploadVendorService $userUploadVendorService
     *
     * @throws UnknownVendorServiceException
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, SourceRepository $sourceRepo, UserUploadVendorService $userUploadVendorService)
    {
        $this->em = $entityManager;
        $this->bus = $bus;

        $this->sourceRepo = $sourceRepo;

        // Load vendor here to ensure that it's only load once.
        $this->vendor = $userUploadVendorService->getVendorEntity();
    }

    /**
     * @param CoverUserUploadMessage $UserUploadMessage
     *
     * @throws \Doctrine\ORM\Query\QueryException
     */
    public function __invoke(CoverUserUploadMessage $UserUploadMessage)
    {
        $identifier = $UserUploadMessage->getIdentifier();

        /** @var Source[] $sources */
        $sources = $this->sourceRepo->findByMatchIdList($UserUploadMessage->getIdentifierType(), [$identifier => ''], $this->vendor);

        switch ($UserUploadMessage->getOperation()) {
            case VendorState::UPDATE:
            case VendorState::INSERT:
                $message = new VendorImageMessage();
                $message->setOperation(VendorState::UPDATE);
                if ($this->createUpdateSource($identifier, $sources, $UserUploadMessage)) {
                    $message->setOperation(VendorState::INSERT);
                }
                break;

            case VendorState::DELETE:
                $message = new DeleteMessage();
                $message->setOperation(VendorState::DELETE);
                break;
        }

        $message->setIdentifier($identifier)
            ->setVendorId($this->vendor->getId())
            ->setIdentifierType($UserUploadMessage->getIdentifierType());
        $this->bus->dispatch($message);
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
