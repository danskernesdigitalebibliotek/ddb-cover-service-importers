<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;

class SearchIndexElasticService implements SearchIndexInterface
{
    private string $hostUrl;
    private string $newIndexName;
    private string $indexAliasName;

    private Client $client;

    public function __construct(string $bindElasticSearchUrl, string $bindElasticSearchIndeAlias)
    {
        $this->hostUrl = $bindElasticSearchUrl;
        $this->indexAliasName = $bindElasticSearchIndeAlias;
    }

    public function add(IndexItem $item)
    {
        // TODO: Implement add() method.
    }

    public function remove(int $id)
    {
        // TODO: Implement remove() method.
    }

    public function search()
    {
        // TODO: Implement search() method.
    }

    /**
     * @param IndexItem[] $items
     *
     * @throws ClientResponseException
     * @throws SearchIndexException
     * @throws ServerResponseException
     */
    public function bulkAdd(array $items): void
    {
        if (!isset($this->newIndexName)) {
            $this->newIndexName = $this->indexAliasName.'_'.date('Y-m-d-His');
            $this->createIndex($this->newIndexName);
        }

        $params = [];
        foreach ($items as $item) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->newIndexName,
                    '_id' => $item->getId(),
                ],
            ];

            $params['body'][] = $item->toArray();
        }

        $this->getClient()->bulk($params);
    }

    public function switchIndex()
    {
        $existingIndexName = $this->getCurrentActiveIndex();

        $this->refreshIndex($this->newIndexName);

        $client = $this->getClient();
        $client->indices()->updateAliases([
            'body' => [
                'actions' => [
                    [
                        'add' => [
                            'index' => $this->newIndexName,
                            'alias' => $this->indexAliasName,
                        ],
                    ],
                ],
            ],
        ]);
        $client->indices()->delete(['index' => $existingIndexName]);
    }

    /**
     * Get client to communicate with ES.
     *
     * @return client
     *   ElasticSearch client
     *
     * @throws SearchIndexException
     */
    private function getClient(): Client
    {
        if (!isset($this->client)) {
            try {
                $client = ClientBuilder::create()->setHosts([$this->hostUrl])->build();
            } catch (AuthenticationException $e) {
                throw new SearchIndexException($e->getMessage(), $e->getCode());
            }

            $this->client = $client;
        }

        return $this->client;
    }

    /**
     * Refresh index to ensure data is searchable.
     *
     * @param string $indexName
     *   Name of the index to refresh
     *
     * @throws SearchIndexException
     */
    private function refreshIndex(string $indexName): void
    {
        try {
            $this->getClient()->indices()->refresh(['index' => $indexName]);
        } catch (SearchIndexException|ClientResponseException|ServerResponseException $e) {
            throw new SearchIndexException('Unable to create new index', $e->getCode());
        }
    }

    /**
     * Get the current active index base on alias.
     *
     * @return string
     *   The name of the active index
     *
     * @throws SearchIndexException
     */
    private function getCurrentActiveIndex(): string
    {
        $client = $this->getClient();

        try {
            $response = $client->indices()->getAlias(['name' => $this->indexAliasName]);
        } catch (ClientResponseException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), $e->getCode());
        }

        if (200 !== $response->getStatusCode()) {
            throw new SearchIndexException('Unable to get aliases', $response->getStatusCode());
        }

        $aliases = $response->asArray();
        $aliases = array_keys($aliases);

        return array_pop($aliases);
    }

    /**
     * Create new index.
     *
     * @param string $indexName
     *   Name of the index to create
     *
     * @throws SearchIndexException
     */
    private function createIndex(string $indexName): void
    {
        $client = $this->getClient();

        try {
            $response = $client->indices()->create([
                'index' => $indexName,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 5,
                        'number_of_replicas' => 0,
                    ],
                    'mappings' => [
                        'properties' => [
                            'isIdentifier' => [
                                'type' => 'keyword',
                            ],
                            'imageFormat' => [
                                'type' => 'keyword',
                            ],
                            'imageUrl' => [
                                'type' => 'text',
                            ],
                            'width' => [
                                'type' => 'integer',
                            ],
                            'isType' => [
                                'type' => 'keyword',
                            ],
                            'height' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), $e->getCode());
        }

        if (200 !== $response->getStatusCode()) {
            throw new SearchIndexException('Unable to create new index', $response->getStatusCode());
        }
    }
}
