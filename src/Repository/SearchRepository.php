<?php
/**
 * @file
 * Contains Search repository.
 */

namespace App\Repository;

use App\Entity\Search;
use App\Utils\Types\IdentifierType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
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

        return $lastEntity?->getId() ?? null;
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

    /**
     * Find all search base on type or single search by type and identifier.
     *
     * @param string $type
     *   The identifier type
     * @param string|null $identifier
     *   If given limit to this single identifier
     * @param int $limit
     *   Limit the number of rows
     * @param int $offset
     *   The offset to start at
     *
     * @return query
     *   The query build
     */
    public function findSearchesByType(string $type, ?string $identifier, int $limit, int $offset): Query
    {
        $queryBuilder = $this->createQueryBuilder('s');
        $queryBuilder->select('s')
            ->where('s.isType = :type')
            ->setParameter('type', $type);

        if (!is_null($identifier)) {
            $queryBuilder->andWhere('s.isIdentifier = :identifier')
                ->setParameter('identifier', $identifier);
        }

        $queryBuilder->setMaxResults($limit);
        $queryBuilder->setFirstResult($offset);

        $queryBuilder->orderBy('s.id', 'ASC');

        return $queryBuilder->getQuery();
    }

    /**
     * Find single katelog search record base on faust.
     *
     * @param string $faust
     *   Faust to search for katelog record
     *
     * @return false|Search
     *   If non found false else the search found
     */
    public function findKatelogSearchesByFaust(string $faust): false|Search
    {
        $queryBuilder = $this->createQueryBuilder('s');
        $queryBuilder->select('s')
            ->where('s.isType = :type')
            ->andWhere('s.isIdentifier LIKE :faust')
            ->setParameter('type', IdentifierType::PID)
            ->setParameter('faust', '%-katalog:'.$faust);
        $res = $queryBuilder->getQuery()->getResult();

        return reset($res);
    }
}
