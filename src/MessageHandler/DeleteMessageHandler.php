<?php

/**
 * @file
 */

namespace App\MessageHandler;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreNotFoundException;
use App\Message\DeleteMessage;
use App\Service\CoverStore\CoverStoreInterface;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class SearchProcessor.
 */
class DeleteMessageHandler implements MessageHandlerInterface
{
    private $em;
    private $logger;
    private $coverStore;

    /**
     * DeleteProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $informationLogger
     * @param CoverStoreInterface $coverStore
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $informationLogger, CoverStoreInterface $coverStore)
    {
        $this->em = $entityManager;
        $this->logger = $informationLogger;
        $this->coverStore = $coverStore;
    }

    /**
     * @param DeleteMessage $message
     */
    public function __invoke(DeleteMessage $message)
    {
        // Look up vendor to get information about image server.
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->find($message->getVendorId());

        try {
            // There may exists a race condition when multiple queues are
            // running. To ensure we delete consistently we need to
            // wrap our search/update/insert in a transaction.
            $this->em->getConnection()->beginTransaction();

            try {
                // Fetch source table rows.
                $sourceRepos = $this->em->getRepository(Source::class);
                $source = $sourceRepos->findOneBy([
                    'matchId' => $message->getIdentifier(),
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
                    $this->logger->error('Source not found in the database', [
                        'service' => 'DeleteProcessor',
                        'identifier' => $message->getIdentifier(),
                        'imageId' => $message->getImageId(),
                    ]);
                }
            } catch (\Exception $exception) {
                $this->em->getConnection()->rollBack();

                $this->logger->error('Database exception: '.get_class($exception), [
                    'service' => 'DeleteProcessor',
                    'message' => $exception->getMessage(),
                    'identifiers' => $message->getIdentifier(),
                ]);
            }
        } catch (ConnectionException $exception) {
            $this->logger->error('Database Connection Exception', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);
        }

        // Delete image in cover store.
        try {
            $this->coverStore->remove($vendor->getName(), $message->getIdentifier());
        } catch (CoverStoreNotFoundException $exception) {
            $this->logger->error('Error removing cover store image - not found', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
                'imageId' => $message->getImageId(),
            ]);

            throw new UnrecoverableMessageHandlingException('Error removing cover store image - not found');
        } catch (CoverStoreException $exception) {
            $this->logger->error('Error removing cover store image', [
                'service' => 'DeleteProcessor',
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
                'imageId' => $message->getImageId(),
            ]);

            throw new UnrecoverableMessageHandlingException('Error removing cover store image');
        }
    }
}
