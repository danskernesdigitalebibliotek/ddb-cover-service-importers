<?php
/**
 * @file
 * Contains a service to populate search index.
 */

namespace App\Service;

use App\Exception\SearchIndexException;
use App\Repository\SearchRepository;
use App\Service\Indexing\IndexingServiceInterface;
use App\Service\Indexing\IndexItem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Class PopulateService.
 */
class PopulateService
{
    final public const BATCH_SIZE = 1000;
    private LockInterface $lock;

    /**
     * PopulateService constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param SearchRepository $searchRepository
     * @param LockFactory $lockFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchRepository $searchRepository,
        private readonly LockFactory $lockFactory,
        private readonly IndexingServiceInterface $indexingService
    ) {
    }

    /**
     * Populate the search index with Search entities.
     *
     * @param int $record_id
     *   Limit populate to this single search record id
     * @param bool $force
     *   Force execution ignoring locks (default false)
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws SearchIndexException
     */
    public function populate(int $record_id = -1, bool $force = false): \Generator
    {
        if ($this->acquireLock($force)) {
            $numberOfRecords = 1;
            if (-1 === $record_id) {
                $numberOfRecords = $this->searchRepository->getNumberOfRecords();
            }

            // Make sure there are entries in the Search table to process.
            if (0 === $numberOfRecords) {
                yield 'No entries in Search table.';

                return;
            }

            $entriesAdded = 0;

            while ($entriesAdded < $numberOfRecords) {
                $items = [];

                $criteria = [];
                if (-1 !== $record_id) {
                    $criteria = ['id' => $record_id];
                }
                $entities = $this->searchRepository->findBy($criteria, ['id' => 'ASC'], self::BATCH_SIZE, $entriesAdded);

                // No more results.
                if (0 === count($entities)) {
                    yield sprintf('%d of %d processed. No more results.', number_format($entriesAdded, 0, ',', '.'), number_format($numberOfRecords, 0, ',', '.'));
                    break;
                }

                foreach ($entities as $entity) {
                    $item = new IndexItem();
                    $item->setId($entity->getId())
                        ->setIsIdentifier($entity->getIsIdentifier())
                        ->setIsType($entity->getIsType())
                        ->setImageUrl($entity->getImageUrl())
                        ->setImageFormat($entity->getImageFormat())
                        ->setWidth($entity->getWidth())
                        ->setHeight($entity->getHeight());
                    $items[] = $item;

                    ++$entriesAdded;
                }

                // Send bulk.
                $this->indexingService->bulkAdd($items);

                // Update progress message.
                yield sprintf('%s of %s added', number_format($entriesAdded, 0, ',', '.'), number_format($numberOfRecords, 0, ',', '.'));

                // Free up memory usages.
                $this->entityManager->clear();
                gc_collect_cycles();
            }

            yield '<info>Switching alias and removing old index</info>';
            $this->indexingService->switchIndex();

            $this->releaseLock();
        } else {
            yield '<error>Process is already running use "--force" to run command</error>';
        }
    }

    /**
     * Get process lock.
     *
     * Used to prevent more than one populating process running at once.
     *
     * @param bool $force
     *  Force execution ignoring locks (default false)
     *
     *   If lock acquired true else false
     */
    private function acquireLock(bool $force = false): bool
    {
        // Get lock with an TTL of 1 hour, which should be more than enough to populate ES.
        $this->lock = $this->lockFactory->createLock('app:search:populate:lock', 3600, false);

        if ($this->lock->acquire() || $force) {
            return true;
        }

        return false;
    }

    /**
     * Release the populating process lock.
     */
    private function releaseLock(): void
    {
        $this->lock->release();
    }
}
