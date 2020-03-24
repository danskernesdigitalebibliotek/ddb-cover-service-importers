<?php

namespace App\Service;

use App\Entity\Search;
use App\Repository\SearchRepository;
use App\Service\VendorService\ProgressBarTrait;
use Doctrine\ORM\EntityManagerInterface;
use Elasticsearch\ClientBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PopulateService
{
    use ProgressBarTrait;

    const BATCH_SIZE = 100;

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
     */
    public function populate(string $index)
    {
        $this->progressStart('Starting populate process');

        $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

        $params = ['body' => []];

        $lastId = $this->searchRepository->findLastId();
        $entriesAdded = 0;
        $currentId = 0;

        do {
            $startId = $currentId;
            $endId = $currentId + self::BATCH_SIZE;
            $entities = $this->searchRepository->findByIdRangeQuery($startId, $endId)->execute();

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
            }

            // Send bulk.
            $responses = $client->bulk($params);

            // Cleanup.
            $params = ['body' => []];
            unset($responses);
            $this->entityManager->clear();
            gc_collect_cycles();

            // Update progress message.
            $this->progressMessage(sprintf('%d of %d ids processed. %d entries added.', $endId, $lastId, $entriesAdded));

            // Set next id to start from.
            $currentId = $endId;

            $this->progressAdvance();
        } while ($currentId <= $lastId);

        $this->progressFinish();
    }
}
