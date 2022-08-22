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
use Doctrine\ORM\Query\QueryException;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoverUserUploadMessageHandler.
 */
class CoverUserUploadMessageHandler implements MessageHandlerInterface
{
    /**
     * CoverUserUploadMessageHandler constructor.
     *
     * @param EntityManagerInterface $em
     * @param MessageBusInterface $bus
     * @param SourceRepository $sourceRepo
     * @param UserUploadVendorService $userUploadVendorService
     * @param MetricsService $metricsService
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SourceRepository $sourceRepo,
        private readonly UserUploadVendorService $userUploadVendorService,
        private readonly MetricsService $metricsService
    ) {
    }

    /**
     * @param CoverUserUploadMessage $userUploadMessage
     *
     * @throws QueryException
     * @throws UnknownVendorServiceException
     */
    public function __invoke(CoverUserUploadMessage $userUploadMessage)
    {
        $vendor = $this->userUploadVendorService->getVendorEntity();
        $labels = [
            'type' => 'vendor',
            'vendorName' => $vendor->getName(),
            'vendorId' => $vendor->getId(),
        ];

        $message = new VendorImageMessage();
        switch ($userUploadMessage->getOperation()) {
            case VendorState::UPDATE:
            case VendorState::INSERT:
                if ($this->createUpdateSource($userUploadMessage, $vendor)) {
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

        $message->setIdentifier($userUploadMessage->getIdentifier())
            ->setIdentifierType($userUploadMessage->getIdentifierType())
            ->setVendorId($vendor->getId());

        $this->bus->dispatch($message);

        // Free memory.
        $this->em->clear();
    }

    /**
     * Create or update existing source entity in the database.
     *
     *   The process message to build for the event producer
     *   The vendor (user upload vendor)
     *
     *   True on insert and false on update
     *
     * @throws QueryException
     */
    private function createUpdateSource(CoverUserUploadMessage $userUploadMessage, Vendor $vendor): bool
    {
        $identifier = $userUploadMessage->getIdentifier();

        /** @var Source[] $sources */
        $sources = $this->sourceRepo->findByMatchIdList($userUploadMessage->getIdentifierType(), [$identifier => ''], $vendor);

        $isNew = true;
        if (array_key_exists($identifier, $sources)) {
            $source = $sources[$identifier];
            $source->setMatchType($userUploadMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($userUploadMessage->getImageUrl());
            $isNew = false;
        } else {
            $source = new Source();
            $source->setMatchType($userUploadMessage->getIdentifierType())
                ->setMatchId($identifier)
                ->setVendor($vendor)
                ->setDate(new \DateTime())
                ->setOriginalFile($userUploadMessage->getImageUrl());
            $this->em->persist($source);
        }

        // Make it stick.
        $this->em->flush();

        return $isNew;
    }
}
