<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;

interface IndexingServiceInterface
{
    /**
     * Add single item to the index.
     *
     * @param IndexItem $item
     *   Item to add to the index
     *
     * @throws SearchIndexException
     */
    public function index(IndexItem $item): void;

    /**
     * Remove single item from the index.
     *
     * @param int $id
     *   Id of the item to remove
     *
     * @throws SearchIndexException
     */
    public function delete(int $id): void;

    /**
     * Bulk add IndexItem objects.
     *
     * @param IndexItem[] $items
     *   Array of IndexItem to add to the index
     *
     * @throws SearchIndexException
     */
    public function bulk(array $items);

    /**
     * Switch new index with old.
     *
     * @throws SearchIndexException
     */
    public function switchIndex();
}
