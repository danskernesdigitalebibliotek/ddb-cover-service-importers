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

/**
 * Class PopulateService.
 */
class PopulateService
{
    use ProgressBarTrait;

    const BATCH_SIZE = 1000;

    /* @var SearchRepository $searchRepository */
    private $searchRepository;
    /* @var string $elasticHost */
    private $elasticHost;
    /* @var EntityManagerInterface $entityManager */
    private $entityManager;

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
    public function __construct(EntityManagerInterface $entityManager, SearchRepository $searchRepository, string $bindElasticSearchUrl)
    {
        $this->searchRepository = $searchRepository;
        $this->entityManager = $entityManager;

        $this->elasticHost = $bindElasticSearchUrl;

        // Make sure that the sql logger is not enabled to avoid memory issues.
        $entityManager->getConnection()->getConfiguration()->setSQLLogger(null);
    }

    /**
     * Populate the search index with Search entities.
     *
     * @param string $index
     *   The index to populate
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function populate(string $index)
    {
        $this->progressStart('Starting populate process');

        $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

        if (!$client->indices()->exists(['index' => $index])) {
            throw new \RuntimeException('Index must be created before populating it.');
        }

        $params = ['body' => []];

        $numberOfRecords = $this->searchRepository->getNumberOfRecords();
        $lastId = $this->searchRepository->findLastId();

        // Make sure there are entries in the Search table to process.
        if (0 === $numberOfRecords || null === $lastId) {
            $this->progressMessage('No entries in Search table.');
            $this->progressFinish();

            return;
        }

        $entriesAdded = 0;
        $currentId = 0;

        while ($entriesAdded < $numberOfRecords) {
            $entities = $this->searchRepository->findBy([], ['id' => 'ASC'], self::BATCH_SIZE, $entriesAdded);

            // No more results.
            if (0 === count($entities)) {
                $this->progressMessage(sprintf('%d of %d processed. Id: %d. Last id: %d. No more results.', $entriesAdded, $numberOfRecords, $currentId, $lastId));
                break;
            }

            /* @var Search $entity */
            foreach ($entities as $entity) {
                $params['body'][] = [
                    'index' => [
                        '_index' => $index,
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

            // Cleanup.
            $params = ['body' => []];
            $this->entityManager->clear();
            gc_collect_cycles();

            $this->progressAdvance();
        }

        $this->progressFinish();
    }
}
