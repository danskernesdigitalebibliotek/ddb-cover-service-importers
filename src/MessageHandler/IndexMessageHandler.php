<?php

/**
 * @file
 * Index message handler
 */

namespace App\MessageHandler;

use App\Entity\Image;
use App\Entity\Search;
use App\Entity\Source;
use App\Message\IndexMessage;
use App\Utils\OpenPlatform\Material;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use ItkDev\MetricsBundle\Service\MetricsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class IndexMessageHandler.
 */
class IndexMessageHandler implements MessageHandlerInterface
{
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private ManagerRegistry $registry;
    private MetricsService $metricsService;

    /**
     * SearchProcessor constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $informationLogger
     * @param ManagerRegistry $registry
     * @param MetricsService $metricsService
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $informationLogger, ManagerRegistry $registry, MetricsService $metricsService)
    {
        $this->em = $entityManager;
        $this->logger = $informationLogger;
        $this->registry = $registry;
        $this->metricsService = $metricsService;
    }

    public function __invoke(IndexMessage $message)
    {
        try {
            $material = $message->getMaterial();
            $image = $this->getImage($message->getImageId());
            $source = (null !== $image) ? $image->getSource() : null;

            // If image or source are null something is broken in the data,
            // so we can't proceed
            if (null === $image || null === $source) {
                $this->logger->error('Index Ready Event Error', [
                    'service' => 'IndexEventSubscriber',
                    'message' => 'Image and/or Source are null for material',
                    'identifiers' => $material->getIdentifiers(),
                ]);

                return;
            }

            $searchRepos = $this->em->getRepository(Search::class);
            try {
                foreach ($material->getIdentifiers() as $identifier) {
                    /* @var Search $search */
                    $search = $searchRepos->findOneBy([
                        'isIdentifier' => $identifier->getId(),
                        'isType' => $identifier->getType(),
                    ]);

                    if (empty($search)) {
                        // It did not exist, so create new record. Which will automatically update the search indexes
                        // on flush.
                        $search = new Search();
                        $search->setIsType($identifier->getType())
                            ->setIsIdentifier($identifier->getId())
                            ->setImageUrl((string) $image->getCoverStoreURL())
                            ->setImageFormat((string) $image->getImageFormat())
                            ->setWidth((int) $image->getWidth())
                            ->setHeight((int) $image->getHeight())
                            ->setCollection($material->isCollection())
                            ->setSource($source);

                        $this->em->persist($search);
                    } else {
                        if ($this->shouldOverride($material, $source, $search)) {
                            $search->setImageUrl((string) $image->getCoverStoreURL())
                                ->setImageFormat((string) $image->getImageFormat())
                                ->setWidth((int) $image->getWidth())
                                ->setHeight((int) $image->getHeight())
                                ->setCollection($material->isCollection())
                                ->setSource($source);
                        }
                    }

                    $this->em->flush();
                }
            } catch (UniqueConstraintViolationException $exception) {
                // Some vendors have more than one unique identifier in the input data, so to queue processors can try
                // to write the same row to the search table, which is not allowed. So we restart the entity manager to
                // ensure that it can write the last index timestamp to the database. If we do not do this we may
                // end up in a loop of the same records falling again and again.
                $this->metricsService->counter('index_event_unique_violation', 'Index event unique constraint violation', 1, ['type' => 'index']);
                $this->registry->resetManager();
            } catch (\Exception $exception) {
                $this->logger->error('Database exception: '.get_class($exception), [
                    'service' => 'IndexEventSubscriber',
                    'message' => $exception->getMessage(),
                    'identifiers' => $material->getIdentifiers(),
                ]);
            }

            // Set the lasted indexed outside the transaction, so it will always be set even at search entity errors.
            $source->setLastIndexed(new \DateTime());
            $this->em->flush();
        } catch (ConnectionException $exception) {
            $this->logger->error('Database Connection Exception', [
                'service' => 'IndexEventSubscriber',
                'message' => $exception->getMessage(),
                'identifiers' => $message->getMaterial()->getIdentifiers(),
            ]);
        } catch (\Exception $exception) {
            $this->logger->error('Index Exception', [
                'service' => 'IndexEventSubscriber',
                'message' => $exception->getMessage(),
            ]);
        }

        // @TODO: Send message data into the index backend.
    }

    /**
     * Determine if the search record should be overridden.
     *
     * @param Material $material
     *   Material from search result
     * @param source $source
     *   Source entity used for raking
     * @param search $search
     *  Search entity used for raking
     *
     * @return bool
     */
    private function shouldOverride(Material $material, Source $source, Search $search): bool
    {
        // Rank is unique so can never be identical for two different vendors
        // but we need to update search if update image from same vendor.
        $sourceRank = $source->getVendor()->getRank();
        $searchRank = $search->getSource()->getVendor()->getRank();
        if ($sourceRank <= $searchRank) {
            // Collection should not override covers on items that already have unique cover.
            if ($material->isCollection()) {
                // Unless it is marked as a collection search entity then this may be an image update.
                if ($search->isCollection()) {
                    return true;
                }

                return false;
            }

            // Not a collection and rank is higher, so allow override.
            return true;
        }

        return false;
    }

    /**
     * Find image entity in the database.
     *
     * @param int $imageId
     *   Database ID for the image
     *
     * @return Image|null
     *   Image entity if found else null
     */
    private function getImage(int $imageId): ?Image
    {
        $repos = $this->em->getRepository(Image::class);

        return $repos->findOneById($imageId);
    }
}
