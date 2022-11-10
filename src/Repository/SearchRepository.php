<?php
/**
 * @file
 * Contains Search repository.
 */

namespace App\Repository;

use App\Entity\Search;
use App\Entity\Source;
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
     * Find single katalog search record base on faust.
     *
     * @param string $faust
     *   Faust to search for katalog record
     *
     * @return false|Search
     *   If non found false else the search found
     */
    public function findKatalogSearchesByFaust(string $faust): false|Search
    {
        // We use a native SQL query because MATCH ... AGAINST is not supported
        // by doctrine and the function supplied by beberlei/doctrineextensions
        // and others didn't give the needed query.

        $em = $this->getEntityManager();

        $rsm = new Query\ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata(Search::class, 'se');
        $rsm->addJoinedEntityFromClassMetadata(Source::class, 'so', 'se', 'source', ['id' => 'source_id']);

        $sql = 'SELECT * FROM search WHERE is_type = ? AND MATCH(is_identifier) AGAINST (? IN BOOLEAN MODE) LIMIT 1';
        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm);
        $query->setParameter(1, IdentifierType::PID);
        $query->setParameter(2, '+katalog:'.$faust);

        $res = $query->getResult();

        return reset($res);
    }
}
