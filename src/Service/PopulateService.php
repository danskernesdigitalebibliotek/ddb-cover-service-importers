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
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function populate(string $index)
    {
        $this->progressStart('Starting populate process');

        $client = ClientBuilder::create()->setHosts([$this->elasticHost])->build();

        $params = ['body' => []];

        $numberOfRecords = $this->searchRepository->getNumberOfRecords();
        $entriesAdded = 0;

        $query = $this->searchRepository->getAllQuery();
        $iterableResult = $query->iterate();

        foreach ($iterableResult as $row) {
            /* @var \App\Entity\Search $entity */
            $entity = $row[0];

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

            // Free memory when batch size is reached.
            if (0 === ($entriesAdded % self::BATCH_SIZE)) {
                // Send bulk.
                $client->bulk($params);

                // Cleanup.
                $params = ['body' => []];
                $this->entityManager->clear();
                gc_collect_cycles();

                // Update progress message.
                $this->progressMessage(sprintf('%d of %d processed.', $entriesAdded, $numberOfRecords));
                $this->progressAdvance();
            }
        }

        // Send the remaining entries.
        $client->bulk($params);
        $this->progressMessage(sprintf('%d of %d processed.', $entriesAdded, $numberOfRecords));

        $this->progressFinish();
    }
}
