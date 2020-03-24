<?php

namespace App\Repository;

use App\Entity\Search;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Search|null find($id, $lockMode = null, $lockVersion = null)
 * @method Search|null findOneBy(array $criteria, array $orderBy = null)
 * @method Search[]    findAll()
 * @method Search[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SearchRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Search::class);
    }

    /**
     * Find the last id.
     *
     * @return int|null
     */
    public function findLastId()
    {
        $lastEntity = $this->findOneBy([], ['id' => 'desc']);

        return $lastEntity->getId();
    }

    /**
     * Get a query from Search entities by range.
     *
     * @param int $startId
     *   Start index (inclusive)
     * @param int $endId
     *   End index (exclusive)
     *
     * @return \Doctrine\ORM\Query
     */
    public function findByIdRangeQuery(int $startId, int $endId)
    {
        $queryBuilder = $this->createQueryBuilder('e');
        $queryBuilder
            ->andWhere('e.id >= :startId')
            ->andWhere('e.id < :endId')
            ->setParameter('startId', $startId)
            ->setParameter('endId', $endId)
        ;

        return $queryBuilder->getQuery();
    }
}
