<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;

interface SearchIndexInterface
{
    /**
     * Add single item to the index.
     *
     * @param indexItem $item
     *   Item to add to the index
     *
     * @throws SearchIndexException
     */
    public function add(IndexItem $item): void;

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
     * @param IndexItem[] $items
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
