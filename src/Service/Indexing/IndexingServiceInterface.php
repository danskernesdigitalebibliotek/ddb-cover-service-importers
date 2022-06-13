<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;

interface IndexingServiceInterface
{
    /**
     * Add single item to the index.
     *
     * @param IndexItemInterface $item
     *   Item to add to the index
     *
     * @throws SearchIndexException
     */
    public function add(IndexItemInterface $item): void;

    /**
     * Remove single item from the index.
     *
     * @param int $id
     *   Id of the item to remove
     *
     * @throws SearchIndexException
     */
    public function remove(int $id): void;

    public function search();

    /**
     * Bulk add IndexItem objects.
     *
     * @param IndexItemInterface[] $items
     *   Array of IndexItem to add to the index
     *
     * @throws SearchIndexException
     */
    public function bulkAdd(array $items);

    /**
     * Switch new index with old.
     *
     * @throws SearchIndexException
     */
    public function switchIndex();
}
