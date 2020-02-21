<?php

/**
 * @file
 * Handle Cover upload from Upload Cover Service.
 */

namespace App\Queue;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Utils\Message\ProcessMessage;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\TopicSubscriberInterface;
use Interop\Queue\Context;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use Karriere\JsonDecoder\JsonDecoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Class SearchProcessor.
 */
class CoverUploadProcessor implements Processor, TopicSubscriberInterface
{
    private $em;
    private $statsLogger;

    /**
     * DeleteProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $statsLogger
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $statsLogger)
    {
        $this->em = $entityManager;
        $this->statsLogger = $statsLogger;
    }

    /**
     * {@inheritdoc}
     */
    public function process(Message $message, Context $session)
    {
        $jsonDecoder = new JsonDecoder(true);
        $processMessage = $jsonDecoder->decode($message->getBody(), ProcessMessage::class);

        /**
         * @TODO: Add the information to the database "source" table.
         * @TODO: Send Event:INSERT/DELETE into the queue system as an normal vendor.
         */

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return ['DeleteTopic' => [
              'processorName' => 'CoverUploadProcessor',
              'queueName' => 'CoverUploadQueue',
            ],
        ];
    }
}
