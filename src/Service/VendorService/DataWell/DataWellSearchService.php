<?php

/**
 * @file
 * Handle search at the data well to utilize hasCover relations.
 */

namespace App\Service\VendorService\DataWell;

use App\Exception\DataWellVendorException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class SearchService.
 */
class DataWellSearchService
{
    private const SEARCH_LIMIT = 50;

    private string $agency;
    private string $profile;
    private string $searchURL;
    private string $password;
    private string $user;

    /**
     * DataWellSearchService constructor.
     *
     * @param ParameterBagInterface $params
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        ParameterBagInterface $params,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->agency = $params->get('datawell.vendor.agency');
        $this->profile = $params->get('datawell.vendor.profile');
    }

    /**
     * Set search url.
     */
    public function setSearchUrl(string $searchURL): void
    {
        $this->searchURL = $searchURL;
    }

    /**
     * Set username to access the datawell.
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * Set password for the datawell.
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * Perform data well search for given ac source.
     *
     * @param string $acSource
     * @param int $offset
     *
     * @return array (array|bool|mixed)[]
     *
     * @throws DataWellVendorException Throws DataWellVendorException on network error
     * @throws \JsonException
     */
    public function search(string $acSource, int $offset = 1): array
    {
        // Validate that the service configuration have been set.
        if (empty($this->searchURL) || empty($this->user) || empty($this->password)) {
            throw new DataWellVendorException('Missing data well access configuration');
        }

        $pidArray = [];
        try {
            $response = $this->httpClient->request('POST', $this->searchURL, [
                'body' => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:open="http://oss.dbc.dk/ns/opensearch">
                 <soapenv:Header/>
                 <soapenv:Body>
                    <open:searchRequest>
                       <open:query>term.acSource="'.$acSource.'"</open:query>
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
                       <open:stepValue>'.$this::SEARCH_LIMIT.'</open:stepValue>
                       <open:allRelations>1</open:allRelations>
                    <open:relationData>uri</open:relationData>
                    <outputType>json</outputType>
                    </open:searchRequest>
                 </soapenv:Body>
              </soapenv:Envelope>',
            ]);

            $content = $response->getContent();
            $jsonResponse = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (array_key_exists('searchResult', $jsonResponse['searchResponse']['result'])) {
                if ($jsonResponse['searchResponse']['result']['hitCount']['$']) {
                    $pidArray = $this->extractData($jsonResponse);
                }

                // It seems that the "more" in the search result is always "false".
                $more = true;
            } else {
                $more = false;
            }
        } catch (TransportExceptionInterface|RedirectionExceptionInterface|ClientExceptionInterface|ServerExceptionInterface $exception) {
            throw new DataWellVendorException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return [$pidArray, $more, $offset + $this::SEARCH_LIMIT];
    }

    /**
     * Extract data from response.
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
                if (isset($object['identifier'])) {
                    $pid = $object['identifier']['$'];
                    $data[$pid] = null;
                    foreach ($object['relations']['relation'] as $relation) {
                        if ('dbcaddi:hasCover' === $relation['relationType']['$']) {
                            $coverUrl = $relation['relationUri']['$'];
                            $data[$pid] = $coverUrl;
                        }
                    }
                }
            }
        }

        return $data;
    }
}
