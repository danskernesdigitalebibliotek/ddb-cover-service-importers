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
use Nicebooks\Isbn\Isbn;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class SearchService.
 */
class SearchService
{
    final public const SEARCH_LIMIT = 50;

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
        'acIdentifier',
    ];

    private readonly int $searchCacheTTL;
    private readonly string $searchURL;
    private readonly string $searchProfile;
    private readonly int $searchLimit;

    /**
     * SearchService constructor.
     *
     * @param ParameterBagInterface $params
     *   Access to environment variables
     * @param CacheItemPoolInterface $cache
     *   Cache object to store results
     * @param AuthenticationService $authenticationService
     *   The Open Platform authentication service
     * @param HttpClientInterface $httpClient
     *   Http Client
     */
    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly CacheItemPoolInterface $cache,
        private readonly AuthenticationService $authenticationService,
        private readonly HttpClientInterface $httpClient
    ) {
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
     *   Material object with the result
     *
     * @throws MaterialTypeException
     * @throws OpenPlatformSearchException
     */
    public function search(string $identifier, string $type, bool $refresh = false): Material
    {
        try {
            // Try getting item from cache.
            $item = $this->cache->getItem('openplatform.search_query'.str_replace(':', '', $identifier));
        } catch (\Psr\Cache\InvalidArgumentException  $exception) {
            throw new OpenPlatformSearchException('Invalid cache argument');
        }

        // Check if cache should be used if item have been located.
        if ($refresh || !$item->isHit()) {
            try {
                $token = $this->authenticationService->getAccessToken();
                $res = $this->recursiveSearch($token, $identifier, $type);
            } catch (OpenPlatformAuthException|\JsonException|InvalidArgumentException $exception) {
                throw new OpenPlatformSearchException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }

            $material = $this->parseResult($res);

            // Check that the searched for identifier is part of the parsed result. As this is not
            // always the case. e.g. 9788798970804. This will also mean that we trust the information vendor provided
            // information. This will also fix the issue where upload service provide a "katelog" post that we are not
            // able to find in the datawell (doing to the way the datawell works). Because the datawell does not allow
            // for non-scoped search, the result we get will always be scoped to the agency credentials we search with.
            // Materials that are not part of that agency´s collection will not be searchable.
            if (!$material->hasIdentifier($type, $identifier)) {
                if ('identifierISBN' === $type) {
                    $identifier = $this->stripDashes($identifier);
                }
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
     *   Material with all the information collected
     *
     * @throws MaterialTypeException
     */
    private function parseResult(array $result): Material
    {
        $material = new Material();
        foreach ($result as $key => $items) {
            switch ($key) {
                case 'acIdentifier':
                    foreach ($items as $item) {
                        $faust = false;
                        $parts = explode('|', (string) $item);
                        if (2 === count($parts)) {
                            $faust = reset($parts);
                        }
                        if (false !== $faust && is_numeric($faust)) {
                            $material->addIdentifier(IdentifierType::FAUST, $faust);
                        }
                    }
                    break;

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
                    if (method_exists($material, $method)) {
                        call_user_func([$material, $method], reset($items));
                    }
                    break;
            }
        }

        // Try to detect if this is a collection (used later on to not override existing covers).
        $material->setCollection(!empty($result['title']) && (is_countable($result['title']) ? count($result['title']) : 0) > 1);

        return $material;
    }

    /**
     * Strip dashes from string.
     *
     * @param string $str
     *   The string to strip
     *
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
     *   The results currently found. If recursion is completed all the results.
     *
     * @throws OpenPlatformSearchException
     */
    private function recursiveSearch(string $token, string $identifier, string $type, string $query = '', int $offset = 0, array &$results = []): array
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

                    if (!is_null($extraISBN)) {
                        $query = 'term.isbn='.$extraISBN.' OR ';
                    }
                    $query .= 'term.isbn='.$identifier;
                    break;

                case IdentifierType::FAUST:
                    // Search after rec.id on basis posts only. This is to prevent match in rec.id between non-related
                    // posts.
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

        $response = $this->httpClient->request('POST', $this->searchURL, [
            'json' => [
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

        $content = $response->getContent();
        $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

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

        // If there are more results get the next chunk and results are smaller than the limit.
        if (isset($json['hitCount']) && false !== $json['more'] && (is_countable($results['pid']) ? count($results['pid']) : 0) < $this->searchLimit) {
            $this->recursiveSearch($token, $identifier, $type, $query, $offset + $this::SEARCH_LIMIT, $results);
        } elseif (!isset($results['basicSearchPerformed']) && isset($results['pid']) && !$this->isBasicInArray($results['pid'])) {
            // As we are using a library's open platform access it may have a "påhængsposter/lokaleposter" which
            // prevents os from getting the basic post. This can only be fixed by asking the open platform for the same
            // search without the katelog post (which than will return the basic post).
            $query = $query.' not rec.id=katalog';

            // To ensure that we don't end in a loop, if no basic post is found this extra stopgab is added.
            $results['basicSearchPerformed'] = true;

            $this->recursiveSearch($token, $identifier, $type, $query, $offset, $results);
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
     *   The ISBN converted to the opposite format or null if conversion not possible
     */
    private function convertIsbn(string $isbn): ?string
    {
        $extraISBN = null;
        try {
            $isbn = Isbn::of($isbn);
            // Only ISBN-13 numbers starting with 978 can be converted to an ISBN-10.
            if ($isbn->is13() and $isbn->isConvertibleTo10()) {
                $extraISBN = (string) $isbn->to10();
            } elseif ($isbn->is10()) {
                $extraISBN = (string) $isbn->to13();
            }
        } catch (\Exception) {
            // Exception is thrown if the ISBN conversion fail. Fallback to setting extra ISBN to null.
            $extraISBN = null;
        }

        return $extraISBN;
    }

    /**
     * Helper function to check if basic post id exists in an array.
     *
     * @param array $pids
     *   Array with data well post ids
     *
     *   True if basic post id exists else false
     */
    private function isBasicInArray(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (false !== mb_strpos((string) $pid, '870970-basis')) {
                return true;
            }
        }

        return false;
    }
}
