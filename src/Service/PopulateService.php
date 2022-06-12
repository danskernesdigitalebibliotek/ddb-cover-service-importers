<?php
/**
 * @file
 * Contains a service to populate search index.
 */

namespace App\Service;

use App\Repository\SearchRepository;
use App\Service\Indexing\IndexItem;
use App\Service\Indexing\SearchIndexElasticService;
use App\Service\Indexing\SearchIndexInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Class PopulateService.
 *
 * @TODO: move into indexes client
 * @see https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/index.html
 */
class PopulateService
{
    public const BATCH_SIZE = 1000;

    private SearchRepository $searchRepository;
    private EntityManagerInterface $entityManager;
    private LockFactory $lockFactory;
    private LockInterface $lock;
    private SearchIndexElasticService $indexService;

    /**
     * PopulateService constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param SearchRepository $searchRepository
     * @param LockFactory $lockFactory
     */
    public function __construct(EntityManagerInterface $entityManager, SearchRepository $searchRepository, LockFactory $lockFactory, SearchIndexElasticService $searchIndex)
    {
        $this->searchRepository = $searchRepository;
        $this->entityManager = $entityManager;

        $this->lockFactory = $lockFactory;
        $this->indexService = $searchIndex;
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
     *
     * @return \Generator
     */
    public function populate(int $record_id = -1, bool $force = false): \Generator
    {
        if ($this->acquireLock($force)) {
            $numberOfRecords = 1;
            $lastId = $record_id;
            if (-1 === $record_id) {
                $numberOfRecords = $this->searchRepository->getNumberOfRecords();
                $lastId = $this->searchRepository->findLastId();
            }

            // Make sure there are entries in the Search table to process.
            if (0 === $numberOfRecords || null === $lastId) {
                yield 'No entries in Search table.';
                return;
            }

            $entriesAdded = 0;
            $currentId = 0;

            while ($entriesAdded < $numberOfRecords) {
                $items = [];

                $criteria = [];
                if (-1 !== $record_id) {
                    $criteria = ['id' => $record_id];
                }
                $entities = $this->searchRepository->findBy($criteria, ['id' => 'ASC'], self::BATCH_SIZE, $entriesAdded);

                // No more results.
                if (0 === count($entities)) {
                    yield sprintf('%d of %d processed. Id: %d. Last id: %d. No more results.', $entriesAdded, $numberOfRecords, $currentId, $lastId);
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
                    $currentId = $entity->getId();
                }

                // Send bulk.
                $this->indexService->bulkAdd($items);

                // Update progress message.
                yield sprintf('%s of %s added. Current id: %d. Last id: %d.', number_format($entriesAdded, 0, ',', '.'), number_format($numberOfRecords, 0, ',', '.'), $currentId, $lastId);

                // Free up memory usages.
                $this->entityManager->clear();
                gc_collect_cycles();
            }

            yield '<info>Switching alias and removing old index</info>';
            $this->indexService->switchIndex();

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
     * @return bool
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
