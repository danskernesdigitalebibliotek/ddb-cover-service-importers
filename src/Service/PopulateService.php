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
use Elasticsearch\ClientBuilder;
use FOS\ElasticaBundle\Exception\AliasIsIndexException;
use FOS\ElasticaBundle\Index\IndexManager;
use FOS\ElasticaBundle\Index\Resetter;

/**
 * Class PopulateService.
 */
class PopulateService
{
    use ProgressBarTrait;

    public const BATCH_SIZE = 1000;

    private SearchRepository $searchRepository;
    private string $elasticHost;
    private EntityManagerInterface $entityManager;
    private IndexManager $indexManager;
    private Resetter $resetter;

    /**
     * PopulateService constructor.
     *
     * @param EntityManagerInterface $entityManager
     *   The entity manager
     * @param SearchRepository $searchRepository
     *   The Search repository
     * @param string $bindElasticSearchUrl
     *   The ElasticSearch endpoint url
     */
    public function __construct(EntityManagerInterface $entityManager, SearchRepository $searchRepository, IndexManager $indexManager, Resetter $resetter, string $bindElasticSearchUrl)
    {
        $this->searchRepository = $searchRepository;
        $this->entityManager = $entityManager;

        $this->elasticHost = $bindElasticSearchUrl;
        $this->indexManager = $indexManager;
        $this->resetter = $resetter;
    }

    /**
     * Populate the search index with Search entities.
     *
     * @param int $record_id
     *   Limit populate to this single search record id
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     *
     * @return void
     */
    public function populate(int $record_id = -1): void
    {
        $this->progressStart('Starting populate process');

        $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

        $indexes = array_keys($this->indexManager->getAllIndexes());
        foreach ($indexes as $indexName) {
            // When aliases is configured this reset will create a new index in ES.
            $this->resetter->resetIndex($indexName, true);

            // Get information about the newly created index and get its name.
            $index = $this->indexManager->getIndex($indexName);

            $numberOfRecords = 1;
            $lastId = $record_id;
            if (-1 === $record_id) {
                $numberOfRecords = $this->searchRepository->getNumberOfRecords();
                $lastId = $this->searchRepository->findLastId();
            }
            // Make sure there are entries in the Search table to process.
            if (0 === $numberOfRecords || null === $lastId) {
                $this->progressMessage('No entries in Search table.');
                $this->progressFinish();

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
                    $this->progressMessage(sprintf('%d of %d processed. Id: %d. Last id: %d. No more results.', $entriesAdded, $numberOfRecords, $currentId, $lastId));
                    break;
                }

                foreach ($entities as $entity) {
                    $params['body'][] = [
                        'index' => [
                            '_index' => $index->getName(),
                            '_id' => $entity->getId(),
                            '_type' => 'search',
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
                $this->progressMessage(sprintf('%d of %d added. Id: %d. Last id: %d.', $entriesAdded, $numberOfRecords, $currentId, $lastId));

                // Free up memory usages.
                $this->entityManager->clear();
                gc_collect_cycles();

                $this->progressAdvance();
            }

            $this->progressMessage(sprintf('<info>Refreshing</info> <comment>%s</comment>', $index->getName()));
            $index->refresh();

            $this->progressMessage(sprintf('<info>Switching alias</info> <comment>%s -> %s</comment>', $index->getOriginalName(), $index->getName()));

            // Switching the alias and deleting the old index.
            try {
                $this->resetter->switchIndexAlias($indexName, true);
            } catch (AliasIsIndexException $exception) {
                $this->progressMessage('<info>Failed to switch alias, please to it by hand</info>');
            }
        }

        $this->progressFinish();
    }
}
