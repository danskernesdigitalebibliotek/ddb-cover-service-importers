<?php
/**
 * @file
 * Contains a service to populate search index.
 */

namespace App\Service;

use App\Entity\Search;
use App\Repository\SearchRepository;
use App\Service\VendorService\ProgressBarTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
//use Elasticsearch\ClientBuilder;
//use FOS\ElasticaBundle\Exception\AliasIsIndexException;
//use FOS\ElasticaBundle\Index\IndexManager;
//use FOS\ElasticaBundle\Index\Resetter;
use Elastic\Elasticsearch\ClientBuilder;
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
    private string $elasticHost;
    private EntityManagerInterface $entityManager;
//    private IndexManager $indexManager;
//    private Resetter $resetter;
    private LockFactory $lockFactory;
    private LockInterface $lock;

    /**
     * PopulateService constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param SearchRepository $searchRepository
     * @param LockFactory $lockFactory
     * @param string $bindElasticSearchUrl
     */
    public function __construct(EntityManagerInterface $entityManager, SearchRepository $searchRepository, LockFactory $lockFactory, string $bindElasticSearchUrl)
    {
        $this->searchRepository = $searchRepository;
        $this->entityManager = $entityManager;

        $this->elasticHost = $bindElasticSearchUrl;
//        $this->indexManager = $indexManager;
//        $this->resetter = $resetter;
        $this->lockFactory = $lockFactory;
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
            $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

            // @TODO: Move basic index prefix into config.
            $indexName = 'coverservice_'.date('Y-m-d-His');
            $aliasName = 'coverservice';

            $response = $client->indices()->getAlias(['name' => $aliasName]);
            if ($response->getStatusCode() !== 200) {
                yield '<error>Unable to get aliases</error>';
                return;
            }

            $aliases = $response->asArray();
            $aliases = array_keys($aliases);
            $existingIndexName = array_pop($aliases);

            $response = $client->indices()->create([
                'index' => $indexName,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 5,
                        'number_of_replicas' => 0,
                    ],
                    'mappings' => [
                        'properties' => [
                            'isIdentifier' => [
                                "type" => "keyword",
                            ],
                            'imageFormat' => [
                                "type" => "keyword",
                            ],
                            'imageUrl' => [
                                "type" => "text",
                            ],
                            'width' => [
                                "type" => "integer",
                            ],
                            'isType' => [
                                "type" => "keyword",
                            ],
                            'height' => [
                                "type" => "integer",
                            ],
                        ],
                    ]
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                yield '<error>Unable to create index</error>';
                return;
            }

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
                $params = ['body' => []];

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
                    $params['body'][] = [
                        'index' => [
                            '_index' => $indexName,
                            '_id' => $entity->getId(),
                        ],
                    ];

                    $params['body'][] = [
                        'isIdentifier' => $entity->getIsIdentifier(),
                        'isType' => $entity->getIsType(),
                        'imageUrl' => $entity->getImageUrl(),
                        'imageFormat' => $entity->getImageFormat(),
                        'width' => $entity->getWidth(),
                        'height' => $entity->getHeight(),
                    ];

                    ++$entriesAdded;
                    $currentId = $entity->getId();
                }

                // Send bulk.
                $client->bulk($params);

                // Update progress message.
                yield sprintf('%s of %s added. Current id: %d. Last id: %d.', number_format($entriesAdded, 0, ',', '.'), number_format($numberOfRecords, 0, ',', '.'), $currentId, $lastId);

                // Free up memory usages.
                $this->entityManager->clear();
                gc_collect_cycles();
            }

            $client->indices()->refresh(['index' => $indexName]);
            yield sprintf('<info>Refreshing</info> <comment>%s</comment>', $indexName);

            yield sprintf('<info>Switching alias</info> <comment>%s -> %s</comment>', $existingIndexName, $indexName);
            $client->indices()->updateAliases([
                'body' => [
                    'actions' => [
                        [
                            'add' => [
                                'index' => $indexName,
                                'alias' => $aliasName,
                            ]
                        ]
                    ]
                ]
            ]);
            $client->indices()->delete(['index' => $existingIndexName]);

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
