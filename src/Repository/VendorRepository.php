<?php

namespace App\Repository;

use App\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VendorRepository extends ServiceEntityRepository
{
    /**
     * VendorRepository constructor.
     *
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    /**
     * Get the maximum rank value from vendors.
     *
     * @return mixed
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getMaxRank()
    {
        return $this->createQueryBuilder('v')
            ->select('MAX(v.rank) AS max_rank')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
