<?php

namespace App\Service\VendorService;

use App\Entity\Vendor;
use App\Exception\DuplicateVendorServiceException;
use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;

/**
 * Class VendorServiceFactory.
 */
class VendorServiceFactory
{
    private $vendorServices;
    private $em;

    private $vendors = [];

    /**
     * VendorFactoryService constructor.
     *
     * @param iterable $vendors
     * @param EntityManagerInterface $entityManager
     *
     * @throws DuplicateVendorServiceException
     * @throws IllegalVendorServiceException
     */
    public function __construct(iterable $vendors, EntityManagerInterface $entityManager)
    {
        $ids = [];
        foreach ($vendors as $vendor) {
            // We are using the classname to match to config row in vendor db table
            $className = \get_class($vendor);
            $this->vendorServices[$className] = $vendor;

            if (0 === $vendor->getVendorId() || !is_int($vendor->getVendorId())) {
                throw new IllegalVendorServiceException('VENDOR_ID must be a non-zero integer. Illegal value detected in '.$className);
            }
            if (\in_array($vendor->getVendorId(), $ids, false)) {
                throw new DuplicateVendorServiceException('Vendor services must have a unique VENDOR_ID. Duplicate id detected in '.$className);
            }
            $ids[] = $vendor->getVendorId();

            // Store found vendors
            $this->vendors[] = $vendor;
        }

        $this->em = $entityManager;
    }

    /**
     * Insert missing VendorServices in DB.
     *
     * Pre-populates the Vendor table with rows for each available vendor services.
     * Inserts only id, classname and default name, not possible config parameters.
     *
     * @return int The number of vendor rows inserted
     *
     * @throws NonUniqueResultException
     *
     * @psalm-return 0|positive-int
     */
    public function populateVendors()
    {
        $vendorRepos = $this->em->getRepository(Vendor::class);

        $result = $vendorRepos->getMaxRank();
        $maxRank = intdiv($result['max_rank'], 10) * 10;

        $inserted = 0;

        foreach ($this->vendorServices as $className => $vendorService) {
            $vendor = $vendorRepos->findOneByClass($className);

            if (!$vendor) {
                $name = substr($className, strrpos($className, '\\') + 1);
                $name = str_replace('VendorService', '', $name);
                $maxRank += 10;

                $vendor = new Vendor();
                $vendor->setId($vendorService->getVendorId());
                $vendor->setClass($className);
                $vendor->setName($name);
                $vendor->setRank($maxRank);

                $this->em->persist($vendor);

                ++$inserted;
            }
        }
        $this->em->flush();

        return $inserted;
    }

    /**
     * Get all vendor services.
     *
     * @return array
     */
    public function getVendorServices(): array
    {
        return $this->vendorServices;
    }

    /**
     * Get names of all vendor services that have been detected.
     *
     * Only vendors that implements the VendorServiceInterface.
     *
     * @return array
     *
     * @psalm-return list<mixed>
     */
    public function getVendorNames(): array
    {
        $names = [];
        foreach ($this->getVendorServices() as $vendorService) {
            $names[] = $vendorService->getVendorName();
        }

        return $names;
    }

    /**
     * Get the vendor service from class name.
     *
     * @param string $class
     *
     * @return VendorServiceInterface
     *
     * @throws UnknownVendorServiceException
     */
    public function getVendorServiceByClass(string $class): VendorServiceInterface
    {
        if (!array_key_exists($class, $this->vendorServices)) {
            throw new UnknownVendorServiceException('Unknown vendor service: '.$class);
        }

        return $this->vendorServices[$class];
    }

    /**
     * Get the vendor service from vendor name.
     *
     * @param string $name
     *
     * @return VendorServiceInterface
     *
     * @throws UnknownVendorServiceException
     */
    public function getVendorServiceByName(string $name): VendorServiceInterface
    {
        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->findOneByName($name);

        return $this->getVendorServiceByClass($vendor->getClass());
    }
}
