<?php

namespace App\Repository;

use App\Entity\Source;
use App\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\QueryException;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SourceRepository extends ServiceEntityRepository
{
    /**
     * SourceRepository constructor.
     *
     * @param RegistryInterface $registry
     */
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Source::class);
    }

    /**
     * Find sources from list of match IDs and vendor.
     *
     * @param string $matchType
     * @param array $matchIdList
     * @param Vendor $vendor
     *
     * @return mixed
     *  Array of sources indexed by match id
     *
     * @throws QueryException
     */
    public function findByMatchIdList(string $matchType, array $matchIdList, Vendor $vendor)
    {
        if (key($matchIdList)) {
            $idList = array_keys($matchIdList);
        } else {
            $idList = $matchIdList;
        }

        return $this->createQueryBuilder('s')
            ->andWhere('s.matchType = (:type)')
            ->andWhere('s.matchId IN (:ids)')
            ->andWhere('s.vendor = (:vendor)')
            ->setParameter('type', $matchType)
            ->setParameter('ids', $idList)
            ->setParameter('vendor', $vendor, Vendor::class)
            ->orderBy('s.matchId', 'ASC')
            ->indexBy('s', 's.matchId')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete all sources not found in given list match IDs.
     *
     * @param array $matchIdList
     * @param Vendor $vendor
     *
     * @return mixed
     */
    public function removeIdsNotInList(array $matchIdList, Vendor $vendor)
    {
        return $this->createQueryBuilder('s')
            ->delete('App:Source', 's')
            ->andWhere('s.matchId NOT IN (:ids)')
            ->andWhere('s.vendor = (:vendor)')
            ->setParameter('ids', $matchIdList)
            ->setParameter('vendor', $vendor, Vendor::class)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find single Source entity with the highest rank (lowest rank value).
     *
     * @param string $type
     *   The identifier type
     * @param string $identifier
     *   The identifier to lookup
     *
     * @return Source|bool
     *   If found Source entity else false
     */
    public function findOneByVendorRank(string $type, string $identifier)
    {
        $sources = $this->createQueryBuilder('s')
            ->andWhere('s.matchId = :identifier')
            ->andWhere('s.matchType = :type')
            ->leftJoin('s.vendor', 'vendor')
            ->orderBy('vendor.rank')
            ->setParameter('identifier', $identifier)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return end($sources);
    }
}
