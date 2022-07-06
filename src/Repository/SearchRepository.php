<?php
/**
 * @file
 * Contains Search repository.
 */

namespace App\Repository;

use App\Entity\Search;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;

class SearchRepository extends ServiceEntityRepository
{
    /**
     * SearchRepository constructor.
     *
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Search::class);
    }

    /**
     * Find the last id.
     *
     *   The last id or null
     */
    public function findLastId(): ?int
    {
        $lastEntity = $this->findOneBy([], ['id' => 'DESC']);

        return $lastEntity->getId() ?? null;
    }

    /**
     * Get number of records.
     *
     *   Number of records in the Search table
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function getNumberOfRecords(): int
    {
        $query = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery();

        return $query->getSingleScalarResult();
    }
}
