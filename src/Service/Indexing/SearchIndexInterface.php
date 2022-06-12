<?php

namespace App\Service\Indexing;

interface SearchIndexInterface {

    public function add();

    public function remove();

    public function search();

    public function bulkAdd(array $items);

    public function switchIndex();

}
