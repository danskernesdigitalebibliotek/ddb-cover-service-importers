<?php

namespace App\Service\Indexing;

interface SearchIndexInterface
{
    public function add(IndexItem $item);

    public function remove(int $id);

    public function search();

    public function bulkAdd(array $items);

    public function switchIndex();
}
