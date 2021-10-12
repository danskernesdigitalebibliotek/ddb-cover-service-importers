<?php

namespace App\Utils\Types;

/**
 * Class VendorStatus.
 *
 * Class used to keep track of vendor updates into the database.
 */
final class VendorStatus
{
    public int $records = 0;
    public int $inserted = 0;
    public int $updated = 0;
    public int $deleted = 0;

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
    public function __construct(int $records = 0, int $inserted = 0, int $updated = 0, int $deleted = 0)
    {
        $this->records = $records;
        $this->inserted = $inserted;
        $this->updated = $updated;
        $this->deleted = $deleted;
    }

    /**
     * Add amount of records to the total count.
     *
     * @param int $amount
     *   The amount to add
     */
    public function addRecords(int $amount): void
    {
        $this->records += $amount;
    }

    /**
     * Add amount of inserted records to the total inserted count.
     *
     * @param int $amount
     *   The amount to add
     */
    public function addInserted(int $amount): void
    {
        $this->inserted += $amount;
    }

    /**
     * Add amount of updated records to the total updated count.
     *
     * @param int $amount
     *   The amount to add
     */
    public function addUpdated(int $amount): void
    {
        $this->updated += $amount;
    }

    /**
     * Add amount of deleted records to the total deleted count.
     *
     * @param int $amount
     *   The amount to add
     */
    public function addDeleted(int $amount): void
    {
        $this->deleted += $amount;
    }
}
