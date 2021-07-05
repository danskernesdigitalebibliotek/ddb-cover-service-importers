<?php

namespace App\Utils\Types;

/**
 * Class VendorStatus.
 *
 * Class used to keep track of vendor updates into the database.
 */
final class VendorStatus
{
    public $records = 0;
    public $inserted = 0;
    public $updated = 0;
    public $deleted = 0;

    /**
     * VendorStatus constructor.
     *
     * @param int $records
     *   Total number of records processed
     * @param int $inserted
     *   Total number of records inserted
     * @param int $updated
     *   Total number of records updated
     * @param int $deleted
     *   Total number of records deleted
     */
    public function __construct($records = 0, $inserted = 0, $updated = 0, $deleted = 0)
    {
        $this->records = $records;
        $this->inserted = $inserted;
        $this->updated = $updated;
        $this->deleted = $deleted;
    }

    /**
     * Add amount of records to the total count.
     *
     * @param $amount
     *   The amount to add
     * @param int $amount
     */
    public function addRecords($amount): void
    {
        $this->records += $amount;
    }

    /**
     * Add amount of inserted records to the total inserted count.
     *
     * @param $amount
     *   The amount to add
     */
    public function addInserted(int $amount): void
    {
        $this->inserted += $amount;
    }

    /**
     * Add amount of updated records to the total updated count.
     *
     * @param $amount
     *   The amount to add
     */
    public function addUpdated(int $amount): void
    {
        $this->updated += $amount;
    }

    /**
     * Add amount of deleted records to the total deleted count.
     *
     * @param $amount
     *   The amount to add
     */
    public function addDeleted($amount): void
    {
        $this->deleted += $amount;
    }
}
