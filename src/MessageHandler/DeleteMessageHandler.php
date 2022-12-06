<?php

/**
 * @file
 */

namespace App\MessageHandler;

use App\Entity\Search;
use App\Entity\Source;
use App\Entity\Vendor;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreNotFoundException;
use App\Message\DeleteMessage;
use App\Message\HasCoverMessage;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\Indexing\IndexingServiceInterface;
use App\Utils\Types\IdentifierType;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class DeleteMessageHandler.
 */
class DeleteMessageHandler implements MessageHandlerInterface
{
    /**
     * DeleteProcessor constructor.
     *
     * @param EntityManagerInterface $em
     * @param LoggerInterface $logger
     * @param CoverStoreInterface $coverStore
     * @param IndexingServiceInterface $indexingService
     * @param MessageBusInterface $bus
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly CoverStoreInterface $coverStore,
        private readonly IndexingServiceInterface $indexingService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param DeleteMessage $message
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function __invoke(DeleteMessage $message)
    {
        // Look up vendor to get information about image server.
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->find($message->getVendorId());

        if (null === $vendor) {
            throw new UnrecoverableMessageHandlingException('Error vendor was not found');
        }

        try {
            // There may exist a race condition when multiple queues are running. To ensure we delete consistently we
            // need to wrap our search/update/insert in a transaction.
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
                    /** @var Search $search */
                    foreach ($searches as $search) {
                        $this->em->remove($search);

                        // Remove this search entity from the search index.
                        $this->indexingService->delete($search->getId());
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

                $this->logger->error('Database exception: '.$exception::class, [
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

        // Add hasCover message to queue system after removing data from the database.
        if (IdentifierType::PID === $message->getIdentifierType()) {
            $hasCoverMessage = new HasCoverMessage();
            $hasCoverMessage->setPid($message->getIdentifier())->setCoverExists(false);
            $this->bus->dispatch($hasCoverMessage);
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
