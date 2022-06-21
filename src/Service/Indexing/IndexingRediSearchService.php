<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;
use MacFJA\RediSearch\Index;
use MacFJA\RediSearch\IndexBuilder;
use MacFJA\RediSearch\Query\Builder\NumericFacet;
use MacFJA\RediSearch\Redis\Client\ClientFacade;
use MacFJA\RediSearch\Redis\Client;

class IndexingRediSearchService implements IndexingServiceInterface
{
    private string $hostUrl;
    private string $newIndexName;
    private string $indexAliasName;

    public function __construct(string $bindIndexingUrl, string $bindIndexingAlias)
    {
        $this->hostUrl = $bindIndexingUrl;
        $this->indexAliasName = $bindIndexingAlias;
    }

    public function add(IndexItem $item): void
    {
        $client = $this->getClient();
        $index = new Index('coverservice', $client);
        $index->addDocumentFromArray([
            'isIdentifier' => $item->getIsIdentifier(),
            'imageFormat' => $item->getImageFormat(),
            'imageUrl' => $item->getImageUrl(),
            'isType' => $item->getIsType(),
            'width' => $item->getWidth(),
            'height' => $item->getHeight(),
        ]);
    }

    public function remove(int $id): void
    {
        $client = $this->getClient();
        $index = new Index('coverservice', $client);

        $queryBuilder = new \MacFJA\RediSearch\Query\Builder();
        $query = $queryBuilder->addElement(NumericFacet::equalsTo('id', $id))->render();

        $search = new \MacFJA\RediSearch\Redis\Command\Search();
        $search->setIndex('coverservice')
            ->setQuery($query);
        $results = $client->execute($search);

        // @TODO: This is a guess.
        $index->deleteDocument($results[0]['hash']);

    }

    public function bulkAdd(array $items)
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function switchIndex()
    {
//        $client = $this->getClient();
//        $index = new Index('coverservice', $client);
//        $index->delete();

        // TODO: Implement switchIndex() method.
    }

    private function createIndex(string $indexName) {
        $client = $this->getClient();

        $builder = new IndexBuilder();
        $builder->setIndex($indexName)
            ->addNumericField('id')
            ->addTextField('isIdentifier')
            ->addTextField('imageFormat')
            ->addTextField('imageUrl')
            ->addTextField('isType')
            ->addNumericField('width')
            ->addNumericField('height')
            ->create($client);

//        $index = new Index($indexName, $client);
//        $index->addAlias();

    }

    private function getClient(): Client
    {
        $clientFacade = new ClientFacade();

        return $clientFacade->getClient(new \Predis\Client('tcp://redisearch:6379'));
    }
}

