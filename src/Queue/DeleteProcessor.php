<?php

/**
 * @file
 */

namespace App\Queue;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreNotFoundException;
use App\Service\CoverStore\CoverStoreInterface;
use App\Utils\Message\ProcessMessage;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\TopicSubscriberInterface;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Karriere\JsonDecoder\JsonDecoder;
use Psr\Log\LoggerInterface;

/**
 * Class SearchProcessor.
 */
class DeleteProcessor implements Processor, TopicSubscriberInterface
{
    private $em;
    private $statsLogger;
    private $coverStore;

    /**
     * DeleteProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     * @param CoverStoreInterface $coverStore
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $statsLogger, CoverStoreInterface $coverStore)
    {
        $this->em = $entityManager;
        $this->statsLogger = $statsLogger;
        $this->coverStore = $coverStore;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        $processMessage = $jsonDecoder->decode($message->getBody(), ProcessMessage::class);

        // Look up vendor to get information about image server.
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->find($processMessage->getVendorId());

        try {
            // There may exists a race condition when multiple queues are
            // running. To ensure we delete consistently we need to
            // wrap our search/update/insert in a transaction.
            $this->em->getConnection()->beginTransaction();

            try {
                // Fetch source table rows.
                $sourceRepos = $this->em->getRepository(Source::class);
                $source = $sourceRepos->findOneBy([
                    'matchId' => $processMessage->getIdentifier(),
                    'vendor' => $vendor,
                ]);

                // Remove search table rows.
                if ($source) {
                    $searches = $source->getSearches();
                    foreach ($searches as $search) {
                        $this->em->remove($search);
                    }

                    // Remove image entity.
                    $image = $source->getImage();
                    if (!empty($image)) {
                        $this->em->remove($image);
                    }

                    // Remove source.
                    $this->em->remove($source);

                    // Make it stick
                    $this->em->flush();
                    $this->em->getConnection()->commit();
                } else {
                    $this->statsLogger->error('Source not found in the database', [
                        'service' => 'DeleteProcessor',
                        'identifier' => $processMessage->getIdentifier(),
                        'imageId' => $processMessage->getImageId(),
                    ]);
                }
            } catch (\Exception $exception) {
                $this->em->getConnection()->rollBack();

                $this->statsLogger->error('Database exception: '.get_class($exception), [
                    'service' => 'DeleteProcessor',
                    'message' => $exception->getMessage(),
                    'identifiers' => $processMessage->getIdentifier(),
                ]);
            }
        } catch (ConnectionException $exception) {
            $this->statsLogger->error('Database Connection Exception', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $processMessage->getIdentifier(),
            ]);
        }

        // Delete image in cover store.
        try {
            $this->coverStore->remove($vendor->getName(), $processMessage->getIdentifier());
        } catch (CoverStoreNotFoundException $exception) {
            $this->statsLogger->error('Error removing cover store image - not found', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $processMessage->getIdentifier(),
                'imageId' => $processMessage->getImageId(),
            ]);

            return self::REJECT;
        } catch (CoverStoreException $exception) {
            $this->statsLogger->error('Error removing cover store image', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $processMessage->getIdentifier(),
                'imageId' => $processMessage->getImageId(),
            ]);

            return self::REJECT;
        }

        return self::ACK;
    }

    // phpcs:disable Symfony.Functions.ScopeOrder.Invalid
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return ['DeleteTopic' => [
              'processorName' => 'DeleteProcessor',
              'queueName' => 'BackgroundQueue',
            ],
        ];
    }
    // phpcs:enable
}
