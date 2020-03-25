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
use Elasticsearch\ClientBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

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
     * @param \Doctrine\ORM\EntityManagerInterface $entityManager
     *   The entity manager
     * @param \App\Repository\SearchRepository $searchRepository
     *   The Search repository
     * @param \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $parameterBag
     *   The parameter bag
     */
    public function __construct(EntityManagerInterface $entityManager, SearchRepository $searchRepository, ParameterBagInterface $parameterBag)
    {
        $this->searchRepository = $searchRepository;
        $this->entityManager = $entityManager;
        $this->elasticHost = $parameterBag->get('elastic.url');
        $entityManager->getConnection()->getConfiguration()->setSQLLogger(null);
    }

    /**
     * Populate the search index with Search entities.
     *
     * @param string $index
     *   The index to populate
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function populate(string $index)
    {
        $this->progressStart('Starting populate process');

        $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

        $params = ['body' => []];

        $entriesAdded = 0;
        $numberOfRecords = $this->searchRepository->getNumberOfRecords();
        $lastId = $this->searchRepository->findLastId();
        $currentId = 0;

        while ($entriesAdded < $numberOfRecords) {
            $entities = $this->searchRepository->findBy([], ['id' => 'ASC'], self::BATCH_SIZE, $entriesAdded);

            // No more results.
            if (0 === count($entities)) {
                $this->progressMessage(sprintf('%d of %d processed. Id: %d. Last id: %d. No more results.', $entriesAdded, $numberOfRecords, $currentId, $lastId));
                $this->progressAdvance();
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
