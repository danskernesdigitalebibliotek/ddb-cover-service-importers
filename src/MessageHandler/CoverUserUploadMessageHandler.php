<?php

/**
 * @file
 * User upload service message handler.
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\UnknownVendorServiceException;
use App\Message\CoverUserUploadMessage;
use App\Message\DeleteMessage;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Service\VendorService\UserUpload\UserUploadVendorService;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\MetricsBundle\Service\MetricsService;
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
    private MetricsService $metricsService;

    /**
     * CoverUserUploadMessageHandler constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param MessageBusInterface $bus
     * @param SourceRepository $sourceRepo
     * @param UserUploadVendorService $userUploadVendorService
     * @param MetricsService $metricsService
     *
     * @throws UnknownVendorServiceException
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, SourceRepository $sourceRepo, UserUploadVendorService $userUploadVendorService, MetricsService $metricsService)
    {
        $this->em = $entityManager;
        $this->bus = $bus;
        $this->sourceRepo = $sourceRepo;
        $this->metricsService = $metricsService;

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

        $labels = [
            'type' => 'vendor',
            'vendorName' => $this->vendor->getName(),
            'vendorId' => $this->vendor->getId(),
        ];

        $message = new VendorImageMessage();
        switch ($UserUploadMessage->getOperation()) {
            case VendorState::UPDATE:
            case VendorState::INSERT:
                if ($this->createUpdateSource($identifier, $sources, $UserUploadMessage)) {
                    $message->setOperation(VendorState::INSERT);
                    $this->metricsService->counter('vendor_inserted_total', 'Number of inserted records', 1, $labels);
                } else {
                    $message->setOperation(VendorState::UPDATE);
                    $this->metricsService->counter('vendor_updated_total', 'Number of updated records', 1, $labels);
                }
                break;

            case VendorState::DELETE:
                $message = new DeleteMessage();
                $message->setOperation(VendorState::DELETE);
                $this->metricsService->counter('vendor_deleted_total', 'Number of deleted records', 1, $labels);
                break;
        }

        $message->setIdentifier($UserUploadMessage->getIdentifier())
            ->setIdentifierType($UserUploadMessage->getIdentifierType())
            ->setVendorId($this->vendor->getId());

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
