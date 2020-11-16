<?php

/**
 * @file
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Message\CoverStoreMessage;
use App\Message\VendorImageMessage;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class VendorImageMessageHandler.
 */
class VendorImageMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $imageValidator;
    private $bus;
    private $statsLogger;

    /**
     * VendorImageProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param VendorImageValidatorService $imageValidator
     * @param MessageBusInterface $bus
     * @param LoggerInterface $statsLogger
     */
    public function __construct(EntityManagerInterface $entityManager, VendorImageValidatorService $imageValidator, MessageBusInterface $bus, LoggerInterface $statsLogger)
    {
        $this->em = $entityManager;
        $this->imageValidator = $imageValidator;
        $this->bus = $bus;
        $this->statsLogger = $statsLogger;
    }

    /**
     * @param VendorImageMessage $message
     *
     * @throws GuzzleException
     */
    public function __invoke(VendorImageMessage $message)
    {
        // Look up vendor to get information about image server.
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->find($message->getVendorId());

        // Look up source to get source url and link it to the image.
        $sourceRepos = $this->em->getRepository(Source::class);
        /** @var Source $source */
        $source = $sourceRepos->findOneBy([
            'matchId' => $message->getIdentifier(),
            'vendor' => $vendor,
        ]);

        if (!is_null($source)) {
            switch ($message->getOperation()) {
                case VendorState::INSERT:
                    $this->processInsert($message, $source);
                    break;

                case VendorState::UPDATE:
                    $this->processUpdate($message, $source);
                    break;

                default:
                    throw new UnrecoverableMessageHandlingException('Unknown vendor operation');
            }
        } else {
            throw new UnrecoverableMessageHandlingException('Source not found');
        }
    }

    /**
     * Handle image inserts. Send update to cover store processor only if vendor image exists.
     *
     * @param VendorImageMessage $message
     * @param Source $source
     *
     * @throws GuzzleException
     */
    private function processInsert(VendorImageMessage $message, Source $source)
    {
        $item = new VendorImageItem();
        $item->setOriginalFile($source->getOriginalFile());

        $this->imageValidator->validateRemoteImage($item);

        if ($item->isFound()) {
            // Hack to send message into new queue.
            $coverStoreMessage = new CoverStoreMessage();
            $coverStoreMessage->setIdentifier($message->getIdentifier())
                ->setIdentifierType($message->getIdentifierType())
                ->setImageId($message->getImageId())
                ->setOperation($message->getOperation())
                ->setUseSearchCache($message->useSearchCache())
                ->setVendorId($message->getVendorId());
            $this->bus->dispatch($coverStoreMessage);

            $source->setOriginalLastModified($item->getOriginalLastModified());
            $source->setOriginalContentLength($item->getOriginalContentLength());
            $this->em->flush();
        } else {
            $source->setOriginalFile(null);
            $source->setOriginalLastModified(null);
            $source->setOriginalContentLength(null);
            $this->em->flush();

            // Log that the image did not exists.
            $this->statsLogger->error('Vendor image error - not found', [
                'service' => 'VendorImageProcessor',
                'identifier' => $message->getIdentifier(),
                'url' => $item->getOriginalFile(),
            ]);

            throw new UnrecoverableMessageHandlingException('Vendor image error - not found');
        }
    }

    /**
     * Handle image updates. Send update to cover store processor only if vendor image is updated.
     *
     * @param VendorImageMessage $message
     * @param Source $source
     *
     * @throws GuzzleException
     */
    private function processUpdate(VendorImageMessage $message, Source $source)
    {
        $item = new VendorImageItem();
        $item->setOriginalFile($source->getOriginalFile());

        $this->imageValidator->isRemoteImageUpdated($item, $source);

        if ($item->isUpdated()) {
            $coverStoreMessage = new CoverStoreMessage();
            $coverStoreMessage->setIdentifier($message->getIdentifier())
                ->setIdentifierType($message->getIdentifierType())
                ->setImageId($message->getImageId())
                ->setOperation($message->getOperation())
                ->setUseSearchCache($message->useSearchCache())
                ->setVendorId($message->getVendorId());
            $this->bus->dispatch($coverStoreMessage);
        } else {
            throw new UnrecoverableMessageHandlingException('Remote image is not updated');
        }
    }
}
