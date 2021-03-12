<?php
/**
 * @file
 * Trait adding set shared configuration functions.
 */

namespace App\Service\VendorService;

/**
 * Trait VendorServiceTrait.
 */
trait VendorServiceTrait
{
    private $limit = 0;
    private $withoutQueue = false;
    private $withUpdates = false;
    private $ignoreLock = false;

    /**
     * {@inheritdoc}
     */
    public function setLimit(int $limit = 0)
    {
        $this->limit = $limit;
    }

    /**
     * {@inheritdoc}
     */
    public function setWithoutQueue(bool $withoutQueue = false)
    {
        $this->withoutQueue = $withoutQueue;
    }

    /**
     * {@inheritdoc}
     */
    public function setWithUpdates(bool $withUpdates = false)
    {
        $this->withUpdates = $withUpdates;
    }

    /**
     * {@inheritdoc}
     */
    public function setIgnoreLock(bool $force = false)
    {
        $this->ignoreLock = $force;
    }
}
