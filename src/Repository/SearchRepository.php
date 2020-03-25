<?php
/**
 * @file
 * Contains Search repository.
 */

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
    /**
     * SearchRepository constructor.
     *
     * @param \Symfony\Bridge\Doctrine\RegistryInterface $registry
     */
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Search::class);
    }

    /**
     * Find the last id.
     *
     * @return int|null
     *   The last id or null
     */
    public function findLastId()
    {
        $lastEntity = $this->findOneBy([], ['id' => 'DESC']);

        return $lastEntity->getId() ?? null;
    }

    /**
     * Get number of records.
     *
     * @return int
     *   Number of records in the Search table
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getNumberOfRecords()
    {
        $query = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery();

        return $query->getSingleScalarResult();
    }
}
