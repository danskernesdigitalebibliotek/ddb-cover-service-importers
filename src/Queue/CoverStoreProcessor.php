<?php

/**
 * @file
 */

namespace App\Queue;

use App\Entity\Image;
use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\CoverStoreCredentialException;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreInvalidResourceException;
use App\Exception\CoverStoreNotFoundException;
use App\Exception\CoverStoreTooLargeFileException;
use App\Exception\CoverStoreUnexpectedException;
use App\Service\CoverStore\CoverStoreInterface;
use App\Utils\Message\ProcessMessage;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\TopicSubscriberInterface;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Karriere\JsonDecoder\JsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class CoverStoreProcessor.
 */
class CoverStoreProcessor implements Processor, TopicSubscriberInterface
{
    private $em;
    private $bus;
    private $statsLogger;
    private $coverStore;

    /**
     * CoverStoreProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param MessageBusInterface $bus
     * @param LoggerInterface $statsLogger
     * @param CoverStoreInterface $coverStore
     */
    public function __construct(EntityManagerInterface $entityManager, MessageBusInterface $bus, LoggerInterface $statsLogger, CoverStoreInterface $coverStore)
    {
        $this->em = $entityManager;
        $this->bus = $bus;
        $this->statsLogger = $statsLogger;
        $this->coverStore = $coverStore;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        $message = $jsonDecoder->decode($message->getBody(), ProcessMessage::class);

        // Look up vendor to get information about image server.
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->find($message->getVendorId());

        // Look up source to get source url and link it to the image.
        $sourceRepos = $this->em->getRepository(Source::class);
        $source = $sourceRepos->findOneBy([
            'matchId' => $message->getIdentifier(),
            'vendor' => $vendor,
        ]);

        try {
            $identifier = $message->getIdentifier();
            $item = $this->coverStore->upload($source->getOriginalFile(), $vendor->getName(), $identifier, [$identifier]);
        } catch (CoverStoreCredentialException $exception) {
            // Access issues.
            $this->statsLogger->error('Access denied to cover store', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);

            return self::REJECT;
        } catch (CoverStoreNotFoundException $exception) {
            // Update image entity and remove source URL.
            $source->setOriginalFile(null);
            $source->setOriginalLastModified(null);
            $source->setOriginalContentLength(null);
            $this->em->flush();

            // Log that the image did not exists.
            $this->statsLogger->error('Cover store error - not found', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
                'url' => $source->getOriginalFile(),
            ]);

            return self::REJECT;
        } catch (CoverStoreTooLargeFileException $exception) {
            $this->statsLogger->error('Cover was to large', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
                'url' => $source->getOriginalFile(),
            ]);

            return self::REJECT;
        } catch (CoverStoreUnexpectedException $exception) {
            $this->statsLogger->error('Cover store unexpected error', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);

            return self::REJECT;
        } catch (CoverStoreInvalidResourceException $exception) {
            $this->statsLogger->error('Cover store invalid resource error', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);

            return self::REJECT;
        } catch (CoverStoreException $exception) {
            $this->statsLogger->error('Cover store error - retry', [
                'service' => 'CoverStoreProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);

            // Service issues, retry the job once. If this a redelivered message reject.
            return $message->isRedelivered() ? self::REJECT : self::REQUEUE;
        }

        // Log information about the image uploaded.
        $this->statsLogger->info('Image cover stored', [
            'service' => 'CoverStoreProcessor',
            'provider' => $item->getVendor(),
            'url' => $item->getUrl(),
            'width' => $item->getWidth(),
            'height' => $item->getHeight(),
            'bytes' => $item->getSize(),
            'format' => $item->getImageFormat(),
        ]);

        // Get image entity, if empty create new image entity else update the
        // entity.
        $image = $source->getImage();
        if (empty($image)) {
            $image = new Image();
            $this->em->persist($image);
        } else {
            // Check that if there exists an auto-generated image. If so delete it
            // from the cover store. Search table indexes should be updated in the
            // SearchProcess job that's up next.
            if ($image->isAutoGenerated()) {
                try {
                    $this->coverStore->remove('Unknown', $message->getIdentifier());
                } catch (Exception $exception) {
                    $this->statsLogger->error('Error removing auto-generated cover - replaced by real cover', [
                        'service' => 'CoverStoreProcessor',
                        'message' => $exception->getMessage(),
                        'identifier' => $message->getIdentifier(),
                    ]);
                }
            }
        }

        $image->setImageFormat($item->getImageFormat())
            ->setSize($item->getSize())
            ->setWidth($item->getWidth())
            ->setHeight($item->getHeight())
            ->setCoverStoreURL($item->getUrl())
            ->setAutoGenerated(false);

        $source->setImage($image);
        $this->em->flush();

        // Send message to next part of the process.
        $message->setImageId($image->getId());
        $this->bus->dispatch($message);

        return self::ACK;
    }

    // phpcs:disable Symfony.Functions.ScopeOrder.Invalid

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [
            'CoverStoreTopic' => [
                'processorName' => 'CoverStoreProcessor',
                'queueName' => 'CoverStoreQueue',
            ],
        ];
    }

    // phpcs:enable
}
