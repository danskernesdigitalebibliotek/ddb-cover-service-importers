<?php

/**
 * @file
 * Handle search at open platform.
 */

namespace App\Service\OpenPlatform;

use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformAuthException;
use App\Exception\OpenPlatformSearchException;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Nicebooks\Isbn\Isbn;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Class SearchService.
 */
class SearchService
{
    private ParameterBagInterface $params;
    private AdapterInterface $cache;
    private AuthenticationService $authenticationService;
    private ClientInterface $client;

    public const SEARCH_LIMIT = 50;

    private array $fields = [
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

    private int $searchCacheTTL;
    private string $searchURL;
    private string $searchProfile;
    private int $searchLimit;

    /**
     * SearchService constructor.
     *
     * @param ParameterBagInterface $params
     *   Access to environment variables
     * @param AdapterInterface $cache
     *   Cache object to store results
     * @param AuthenticationService $authenticationService
     *   The Open Platform authentication service
     * @param ClientInterface $httpClient
     *   Guzzle Client
     */
    public function __construct(ParameterBagInterface $params, AdapterInterface $cache, AuthenticationService $authenticationService, ClientInterface $httpClient)
    {
        $this->params = $params;
        $this->cache = $cache;
        $this->authenticationService = $authenticationService;
        $this->client = $httpClient;

        $this->searchURL = $this->params->get('openPlatform.search.url');
        $this->searchCacheTTL = (int) $this->params->get('openPlatform.search.ttl');
        $this->searchProfile = $this->params->get('openPlatform.search.profile');
        $this->searchLimit = (int) $this->params->get('openPlatform.search.limit');
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
     * @return Material
     *   Material object with the result
     *
     * @throws MaterialTypeException
     * @throws OpenPlatformSearchException
     * @throws OpenPlatformAuthException
     */
    public function search(string $identifier, string $type, bool $refresh = false): Material
    {
        try {
            // Try getting item from cache.
            $item = $this->cache->getItem('openplatform.search_query'.str_replace(':', '', $identifier));
        } catch (InvalidArgumentException $exception) {
            throw new OpenPlatformSearchException('Invalid cache argument');
        }

        // We return the material object and not the $item->get() as that
        // prevents proper testing of the service.
        $material = null;

        // Check if cache should be used if item have been located.
        if ($refresh || !$item->isHit()) {
            try {
                $token = $this->authenticationService->getAccessToken();
                $res = $this->recursiveSearch($token, $identifier, $type);
            } catch (GuzzleException $exception) {
                throw new OpenPlatformSearchException($exception->getMessage(), $exception->getCode());
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
     * @return Material
     *   Material with all the information collected
     *
     * @throws MaterialTypeException
     */
    private function parseResult(array $result): Material
    {
        $material = new Material();
        foreach ($result as $key => $items) {
            switch ($key) {
                case 'pid':
                    foreach ($items as $item) {
                        $material->addIdentifier(IdentifierType::PID, $item);
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

        // Try to detect if this is a collection (used later on to not override existing covers).
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
    private function stripDashes(string $str): string
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
     * @param string $query
     *   Search query to execute. Defaults to empty string, which means that the function will build the query based on
     *   the other parameters.
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
    private function recursiveSearch(string $token, string $identifier, string $type, string $query = '', int $offset = 0, array $results = []): array
    {
        // HACK HACK HACK.
        // Temporary protection against non-real identifiers and search on library only ids.
        if (empty($identifier) || strlen($identifier) <= 6) {
            return $results;
        }

        if ('' === $query) {
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
                    // Search after rec.id on basis posts only. This is to prevent match in rec.id between non
                    // related posts.
                    $query = 'rec.id=870970-basis:'.$identifier;
                    break;

                case IdentifierType::ISSN:
                    $query = 'dkcclterm.in='.$identifier;
                    break;

                default:
                    // This should not be possible
                    throw new OpenPlatformSearchException('Search with unknown identifier type ('.$type.')');
            }
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
        if (isset($json['hitCount']) && false !== $json['more'] && count($results['pid']) < $this->searchLimit) {
            $this->recursiveSearch($token, $identifier, $type, $query, $offset + $this::SEARCH_LIMIT, $results);
        }

        return $results;
    }

    /**
     * Convert ISBN to matching ISBN10 or ISBN13.
     *
     * Will convert the given ISBN to it's opposite format.
     * E.g. convert ISBN10 to 13, and ISBN13 to 10 when possible.
     *
     * @param string $isbn
     *   An ISBN10 or ISBN13 number
     *
     * @return string|null
     *   The ISBN converted to the opposite format or null if conversion not possible
     */
    private function convertIsbn(string $isbn): ?string
    {
        $extraISBN = null;
        try {
            $isbn = Isbn::of($isbn);
            // Only ISBN-13 numbers starting with 978 can be converted to an ISBN-10.
            if ($isbn->is13($isbn) and $isbn->isConvertibleTo10()) {
                $extraISBN = $isbn->to10()->format();
            } elseif ($isbn->is10()) {
                $extraISBN = $isbn->to13()->format();
            }
        } catch (\Exception $exception) {
            // Exception is thrown if the ISBN conversion fail. Fallback to setting extra ISBN to null.
            $extraISBN = null;
        }

        return $extraISBN;
    }
}
