<?php

/**
 * @file
 * Handle search at the data well to utilize hasCover relations.
 */

namespace App\Service\DataWell;

use App\Exception\DataWellVendorException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class DataWellClient.
 */
class DataWellClient
{
    final public const SEARCH_LIMIT = 50;

    /**
     * DataWellSearchService constructor.
     *
     * @param string $agency
     * @param string $profile
     * @param string $searchURL
     * @param string $password
     * @param string $user
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        private readonly string $agency,
        private readonly string $profile,
        private readonly string $searchURL,
        private readonly string $password,
        private readonly string $user,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Perform data well search for given ac source.
     *
     * @param string $query
     * @param int $offset
     *
     * @return array
     *     List of "response content", "more results", "offset"
     *
     * @throws DataWellVendorException
     */
    public function search(string $query, int $offset = 1): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->searchURL, [
                'body' => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:open="http://oss.dbc.dk/ns/opensearch">
                <soapenv:Header/>
                <soapenv:Body>
                <open:searchRequest>
                   <open:query>'.$query.'</open:query>
                   <open:agency>'.$this->agency.'</open:agency>
                   <open:profile>'.$this->profile.'</open:profile>
                   <open:allObjects>0</open:allObjects>
                   <open:authentication>
                      <open:groupIdAut>'.$this->agency.'</open:groupIdAut>
                      <open:passwordAut>'.$this->password.'</open:passwordAut>
                      <open:userIdAut>'.$this->user.'</open:userIdAut>
                   </open:authentication>
                   <open:objectFormat>dkabm</open:objectFormat>
                   <open:start>'.$offset.'</open:start>
                   <open:stepValue>'.self::SEARCH_LIMIT.'</open:stepValue>
                   <open:allRelations>1</open:allRelations>
                <open:relationData>uri</open:relationData>
                <outputType>json</outputType>
                </open:searchRequest>
                </soapenv:Body>
                </soapenv:Envelope>',
            ]);

            $content = $response->getContent();
            $jsonContent = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface $exception) {
            throw new DataWellVendorException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $more = $this->hasMoreResults($jsonContent);

        return [$jsonContent, $more, $offset + self::SEARCH_LIMIT];
    }

    /**
     * Extract data from response.
     *
     * @param array $jsonContent
     *   Array of the json decoded data
     * @param string $coverUrlRelationKey
     *   The datawell relation key that holds the cover URL
     *
     * @return array<string, ?string>
     *   Array of all pid => url pairs found in response
     */
    public function extractCoverUrl(array $jsonContent, string $coverUrlRelationKey): array
    {
        $data = [];

        foreach ($jsonContent['searchResponse']['result']['searchResult'] as $item) {
            foreach ($item['collection']['object'] as $object) {
                if (isset($object['identifier'])) {
                    $pid = (string) $object['identifier']['$'];
                    $data[$pid] = null;
                    foreach ($object['relations']['relation'] as $relation) {
                        if ($coverUrlRelationKey === $relation['relationType']['$']) {
                            $coverUrl = $relation['relationUri']['$'];
                            $data[$pid] = (string) $coverUrl;
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Extract PIDs and matching cover urls from response.
     *
     * @param array $json
     *   Array of the json decoded data
     *
     *   Array of all pid => url pairs found in response
     */
    public function extractData(array $json): array
    {
        $data = [];

        foreach ($json['searchResponse']['result']['searchResult'] as $item) {
            foreach ($item['collection']['object'] as $object) {
                $pid = $object['identifier']['$'];
                $data[$pid] = $object;
            }
        }

        return $data;
    }

    /**
     * Has the search more results.
     *
     * @param array $jsonContent
     *   Json decode result
     *
     * @return bool
     */
    private function hasMoreResults(array $jsonContent): bool
    {
        $more = false;

        if (array_key_exists('searchResult', $jsonContent['searchResponse']['result'])) {
            // It seems that the "more" in the search result is always "false".
            $more = true;
        }

        return $more;
    }
}
