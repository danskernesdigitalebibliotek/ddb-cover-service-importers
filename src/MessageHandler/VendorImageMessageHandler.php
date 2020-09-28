<?php

/**
 * @file
 */

namespace App\Queue;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Message\SearchMessage;
use App\Message\VendorImageMessage;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\Message\ProcessMessage;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\ProducerInterface;
use Enqueue\Client\TopicSubscriberInterface;
use Enqueue\Util\JSON;
use GuzzleHttp\Exception\GuzzleException;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Karriere\JsonDecoder\JsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class VendorImageMessageHandler.
 */
class VendorImageMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $imageValidator;
    private $producer;
    private $statsLogger;

    /**
     * VendorImageProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param VendorImageValidatorService $imageValidator
     * @param ProducerInterface $producer
     * @param LoggerInterface $statsLogger
     */
    public function __construct(EntityManagerInterface $entityManager,VendorImageValidatorService $imageValidator,
                                ProducerInterface $producer, LoggerInterface $statsLogger)
    {
        $this->em = $entityManager;
        $this->imageValidator = $imageValidator;
        $this->producer = $producer;
        $this->statsLogger = $statsLogger;
    }

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
            $this->producer->sendEvent('CoverStoreTopic', JSON::encode($message));

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
                'url' => $source->getOriginalFile(),
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
            $this->producer->sendEvent('CoverStoreTopic', JSON::encode($message));
        } else {
            throw new UnrecoverableMessageHandlingException('Remote image is not updated');
        }
    }
}
