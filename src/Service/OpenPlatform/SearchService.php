<?php

/**
 * @file
 * Handle search at open platform.
 */

namespace App\Service\OpenPlatform;

use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformSearchException;
use App\Exception\PlatformAuthException;
use App\Exception\PlatformSearchException;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Nicebooks\Isbn\IsbnTools;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Class SearchService.
 */
class SearchService
{
    private $params;
    private $cache;
    private $statsLogger;
    private $authenticationService;
    private $client;

    const SEARCH_LIMIT = 50;

    private $fields = [
        'title',
        'creator',
        'date',
        'publisher',
        'pid',
        'identifierISBN',
        'identifierISSN',
        'identifierISMN',
        'identifierISRC',
    ];

    private $searchCacheTTL;
    private $searchURL;
    private $searchProfile;
    private $searchLimit;

    /**
     * SearchService constructor.
     *
     * @param parameterBagInterface $params
     *   Access to environment variables
     * @param adapterInterface $cache
     *   Cache object to store results
     * @param loggerInterface $statsLogger
     *   Logger object to send stats to ES
     * @param authenticationService $authenticationService
     *   The Open Platform authentication service
     * @param ClientInterface $httpClient
     *   Guzzle Client
     */
    public function __construct(ParameterBagInterface $params, AdapterInterface $cache,
                                LoggerInterface $statsLogger, AuthenticationService $authenticationService,
                                ClientInterface $httpClient)
    {
        $this->params = $params;
        $this->cache = $cache;
        $this->statsLogger = $statsLogger;
        $this->authenticationService = $authenticationService;
        $this->client = $httpClient;

        $this->searchURL = $this->params->get('openPlatform.search.url');
        $this->searchCacheTTL = (int) $this->params->get('openPlatform.search.ttl');
        $this->searchProfile = (string) $this->params->get('openPlatform.search.profile');
        $this->searchLimit = (int) $this->params->get('openPlatform.search.ttl');
    }

    /**
     * Search the data well through the open platform.
     *
     * Note: that cache is utilized, hence the result may not be fresh.
     *
     * @param string $identifier
     *   The identifier to search for
     * @param string $type
     *   The type of identifier
     * @param bool $refresh
     *   If set to TRUE the cache is by-passed. Default: FALSE.
     *
     * @return material
     *   Material object with the result
     *
     * @throws PlatformSearchException
     * @throws MaterialTypeException
     * @throws PlatformAuthException
     * @throws InvalidArgumentException
     */
    public function search(string $identifier, string $type, $refresh = false): Material
    {
        // Try getting item from cache.
        $item = $this->cache->getItem('openplatform.search_query'.str_replace(':', '', $identifier));

        // We return the material object and not the $item->get() as that
        // prevents proper testing of the service.
        $material = null;

        // Check if cache should be used if item have been located.
        if ($refresh || !$item->isHit()) {
            try {
                $token = $this->authenticationService->getAccessToken();
                $res = $this->recursiveSearch($token, $identifier, $type);
            } catch (GuzzleException $exception) {
                throw new PlatformSearchException($exception->getMessage(), $exception->getCode());
            }

            $material = $this->parseResult($res);

            // Check that the searched for identifier is part of the parsed result. As this is not
            // always the case. e.g. 9788798970804. This will also mean that we trust the information vendor provided
            // information. This will also fix the issue where upload service provide a "katelog" post that we are not
            // able to find in the datawell (doing to the way the datawell works). Because the datawell does not allow
            // for non-scoped search, the result we get will always be scoped to the agency credentials we search with.
            // Materials that are not part of that agency´s collection will not be searchable.
            if (!$material->hasIdentifier($type, $identifier)) {
                $material->addIdentifier($type, $identifier);
            }

            // If the vendor provided type is PID, then we should be able to get the faust number as well.
            if (IdentifierType::PID === $type) {
                $faust = Material::translatePidToFaust($identifier);
                if (!$material->hasIdentifier(IdentifierType::FAUST, $faust)) {
                    $material->addIdentifier(IdentifierType::FAUST, $faust);
                }
            }

            $item->expiresAfter($this->searchCacheTTL);
            $item->set($material);
            $this->cache->save($item);
        } else {
            $material = $item->get();
        }

        return $material;
    }

    /**
     * Parse the search result from the date well.
     *
     * @param array $result
     *   The results from the data well
     *
     * @return material
     *   Material with all the information collected
     *
     * @throws MaterialTypeException
     */
    private function parseResult(array $result)
    {
        $material = new Material();
        foreach ($result as $key => $items) {
            switch ($key) {
                case 'pid':
                    foreach ($items as $item) {
                        $material->addIdentifier(IdentifierType::PID, $item);

                        // We know that the last part of the PID is the material faust
                        // so we extract that here and add that as a identifier as
                        // well.
                        if (preg_match('/:(1?\d{8}$)/', $item, $matches)) {
                            $material->addIdentifier(IdentifierType::FAUST, $matches[1]);
                        }
                    }
                    break;

                case 'identifierISBN':
                    foreach ($items as $item) {
                        $isbn = $this->stripDashes($item);
                        $material->addIdentifier(IdentifierType::ISBN, $isbn);

                        // Always add the matching ISBN10/13 because we can't trust the datawell
                        // to always provide both.
                        $extraISBN = $this->convertIsbn($isbn);
                        if (!is_null($extraISBN)) {
                            $material->addIdentifier(IdentifierType::ISBN, $extraISBN);
                        }
                    }
                    break;

                case 'identifierISSN':
                    foreach ($items as $item) {
                        $material->addIdentifier(IdentifierType::ISSN, $this->stripDashes($item));
                    }
                    break;

                case 'identifierISMN':
                    foreach ($items as $item) {
                        $material->addIdentifier(IdentifierType::ISMN, $this->stripDashes($item));
                    }
                    break;

                case 'identifierISR':
                    foreach ($items as $item) {
                        $material->addIdentifier(IdentifierType::ISRC, $this->stripDashes($item));
                    }
                    break;

                default:
                    $method = 'set'.ucfirst($key);
                    call_user_func([$material, $method], reset($items));
                    break;
            }
        }

        // Try to detect if this is an collection (used later on to not override existing covers).
        $material->setCollection((!empty($result['title']) && count($result['title']) > 1));

        return $material;
    }

    /**
     * Strip dashes from string.
     *
     * @param string $str
     *   The string to strip
     *
     * @return string
     *   The striped string
     */
    private function stripDashes($str)
    {
        return str_replace('-', '', $str);
    }

    /**
     * Recursive search until no more results exists for the query.
     *
     * This is need as the open platform allows an max limit of 50 elements, so
     * if more results exists this calls it self to get all results.
     *
     * @param string $token
     *   Access token
     * @param string $identifier
     *   The identifier to search for
     * @param string $type
     *   The identifier type
     * @param int $offset
     *   The offset to start getting results
     * @param array $results
     *   The current results array
     *
     * @return array
     *   The results currently found. If recursion is completed all the results.
     *
     * @throws GuzzleException
     * @throws OpenPlatformSearchException
     */
    private function recursiveSearch(string $token, string $identifier, string $type, int $offset = 0, array $results = []): array
    {
        switch ($type) {
            case IdentifierType::PID:
                // If this is a search after a pid simply search for it and not in the search index.
                $query = 'rec.id='.$identifier;
                break;

            case IdentifierType::ISBN:
                // Try to get both ISBN-10 and ISBN-13 into query to match wider.
                $extraISBN = $this->convertIsbn($identifier);

                $query = '';
                if (!is_null($extraISBN)) {
                    $query = 'term.isbn='.$extraISBN.' OR ';
                }
                $query .= 'term.isbn='.$identifier;
                break;

            case IdentifierType::FAUST:
                // Search after rec.id on basis and katelog posts only. This is to prevent match in rec.id between non
                // related posts.
                $query = 'rec.id='.$identifier.' and rec.id any "basis katalog"';
                break;

            case IdentifierType::ISSN:
                $query = 'dkcclterm.in='.$identifier;
                break;

            default:
                // This should not be possible
                throw new OpenPlatformSearchException('Search with unknown identifier type ('.$type.')');
        }

        $response = $this->client->request('POST', $this->searchURL, [
            RequestOptions::JSON => [
                'fields' => $this->fields,
                'access_token' => $token,
                'pretty' => false,
                'timings' => false,
                'q' => $query,
                'offset' => $offset,
                'limit' => $this::SEARCH_LIMIT,
                'profile' => $this->searchProfile,
            ],
        ]);

        $content = $response->getBody()->getContents();
        $json = json_decode($content, true);

        if (isset($json['hitCount']) && $json['hitCount'] > 0) {
            foreach ($json['data'] as $item) {
                // These basic information is also set inside the loop as the last on seams to always be the global
                // post.
                foreach ($this->fields as $field) {
                    if (array_key_exists($field, $item)) {
                        if (!array_key_exists($field, $results)) {
                            $results[$field] = [];
                        }
                        if (!in_array($item[$field], $results[$field])) {
                            $results[$field][] = reset($item[$field]);
                        }
                    }
                }
            }
        }

        // If there are more results get the next chunk and results are smaller then the limit.
        if (isset($json['hitCount']) && false !== $json['more'] && count($results) < $this->searchLimit) {
            $this->recursiveSearch($token, $identifier, $type, $offset + $this::SEARCH_LIMIT, $results);
        }

        return $results;
    }

    /**
     * Convert ISBN to matching ISBN10 or ISBN13.
     *
     * Will convert the given isbn number to it's opposite format.
     * E.g. convert ISBN10 to 13, and ISBN13 to 10 when possible.
     *
     * @param string $isbn
     *   An ISBN10 or ISBN13 number
     *
     * @return string|null
     *   The ISBN number converted to the opposite format or null if conversion not possible
     */
    private function convertIsbn(string $isbn): ?string
    {
        $extraISBN = null;
        $tools = new IsbnTools();
        try {
            if ($tools->isValidIsbn13($isbn)) {
                // Only ISBN-13 numbers starting with 978 can be converted to an ISBN-10.
                $extraISBN = $tools->convertIsbn13to10($isbn);
            } elseif ($tools->isValidIsbn10($isbn)) {
                $extraISBN = $tools->convertIsbn10to13($isbn);
            }
        } catch (\Exception $exception) {
            // Exception is thrown if the ISBN conversion fail. Fallback to setting extra ISBN to false.
        }

        return $extraISBN;
    }
}
