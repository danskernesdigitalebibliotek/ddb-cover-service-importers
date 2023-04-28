<?php

namespace App\Service\Indexing;

use App\Exception\SearchIndexException;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Component\HttpFoundation\Response;

class IndexingElasticService implements IndexingServiceInterface
{
    private ?string $newIndexName = null;

    public function __construct(
        private readonly string $indexAliasName,
        private readonly Client $client,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function index(IndexItem $item): void
    {
        try {
            // Check if index exists.
            $this->getCurrentActiveIndexName();
        } catch (SearchIndexException $e) {
            // Index "not found" so let's create it with the right mappings and add alias for it.
            if (404 === $e->getCode()) {
                $this->newIndexName = $this->indexAliasName.'_'.date('Y-m-d-His');
                $this->createIndex($this->newIndexName);
                $this->refreshIndex($this->newIndexName);
                $this->client->indices()->updateAliases([
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
            }
        }

        /** @var IndexItem $item */
        $params = [
            'index' => $this->indexAliasName,
            'id' => $item->getId(),
            'body' => $item->toArray(),
        ];

        try {
            /** @var Elasticsearch $response */
            $response = $this->client->index($params);

            if (Response::HTTP_OK !== $response->getStatusCode() && Response::HTTP_CREATED !== $response->getStatusCode() && Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
                throw new SearchIndexException('Unable to add item to index', $response->getStatusCode());
            }
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(int $id): void
    {
        $params = [
            'index' => $this->indexAliasName,
            'id' => $id,
        ];

        try {
            /** @var Elasticsearch $response */
            $response = $this->client->delete($params);

            if (Response::HTTP_OK !== $response->getStatusCode() && Response::HTTP_ACCEPTED !== $response->getStatusCode() && Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
                throw new SearchIndexException('Unable to delete item from index', $response->getStatusCode());
            }
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function bulk(array $items): void
    {
        try {
            if (null === $this->newIndexName) {
                $this->newIndexName = $this->indexAliasName.'_'.date('Y-m-d-His');
                $this->createIndex($this->newIndexName);
            }

            $params = [];
            foreach ($items as $item) {
                /* @var IndexItem $item */
                $params['body'][] = [
                    'index' => [
                        '_index' => $this->newIndexName,
                        '_id' => $item->getId(),
                    ],
                ];

                $params['body'][] = $item->toArray();
            }

            $this->client->bulk($params);
        } catch (ClientResponseException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Switch new index with old by updating alias.
     *
     * @throws SearchIndexException
     */
    public function switchIndex(): void
    {
        if (null === $this->newIndexName) {
            throw new SearchIndexException('New index name cannot be null');
        }

        try {
            $existingIndexName = $this->getCurrentActiveIndexName();
            $this->refreshIndex($this->newIndexName);

            $this->client->indices()->updateAliases([
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
            $this->client->indices()->delete(['index' => $existingIndexName]);
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
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
            $this->client->indices()->refresh(['index' => $indexName]);
        } catch (ClientResponseException|ServerResponseException $e) {
            throw new SearchIndexException('Unable to refresh index', (int) $e->getCode(), $e);
        }
    }

    /**
     * Get the current active index name base on alias.
     *
     * @return string
     *   The name of the active index
     *
     * @throws SearchIndexException
     */
    private function getCurrentActiveIndexName(): string
    {
        try {
            /** @var Elasticsearch $response */
            $response = $this->client->indices()->getAlias(['name' => $this->indexAliasName]);

            if (Response::HTTP_OK !== $response->getStatusCode()) {
                throw new SearchIndexException('Unable to get aliases', $response->getStatusCode());
            }

            $aliases = $response->asArray();
            $aliases = array_keys($aliases);

            return array_pop($aliases);
        } catch (ClientResponseException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
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
        try {
            /** @var Elasticsearch $response */
            $response = $this->client->indices()->create([
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
                            "generic" => [
                                "type" => "boolean",
                            ],
                        ],
                    ],
                ],
            ]);

            if (Response::HTTP_OK !== $response->getStatusCode() && Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
                throw new SearchIndexException('Unable to create new index', $response->getStatusCode());
            }
        } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
            throw new SearchIndexException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
