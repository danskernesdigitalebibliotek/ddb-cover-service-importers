<?php

/**
 * @file
 * Contains TheMovieDatabaseApiClient for searching in TheMovieDatabase.
 */

namespace App\Service\VendorService\TheMovieDatabase;

use Oefenweb\DamerauLevenshtein\DamerauLevenshtein;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class TheMovieDatabaseApiClient.
 */
class TheMovieDatabaseApiClient
{
    private const SEARCH_URL = 'https://api.themoviedb.org/3/search/movie';
    private const BASE_IMAGE_PATH = 'https://image.tmdb.org/t/p/original';

    /**
     * TheMovieDatabaseApiClient constructor.
     *
     * @param string $apiKey
     * @param HttpClientInterface $httpClient
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Search in the movie database for a poster url by title, possible years and director.
     *
     * @param string|null $title
     *   The title of the item
     * @param string $originalTitle
     *   The original title of the item
     * @param array $originalYears
     *   Array of possible release years for the item
     * @param array $creators
     *   The creators of the movie
     * @param string $language
     *   The language of the movie
     *
     * @return string|null
     *   The poster url or null
     */
    public function searchPosterUrl(
        ?string $title,
        string $originalTitle,
        array $originalYears,
        array $creators,
        string $language
    ): ?string {
        // Bail out if the required information is not supplied.
        if (null === $title || empty($originalYears) || empty($creators)) {
            return null;
        }

        // Search for the given years. TMDB does not support searching by range of release years.
        foreach ($originalYears as $originalYear) {
            $posterUrl = $this->searchPosterUrlByYear($title, $originalTitle, $originalYear, $creators, $language);
            if (null !== $posterUrl) {
                return $posterUrl;
            }
        }

        // Try the search again but for "originalTitle"
        if ('' !== $originalTitle) {
            foreach ($originalYears as $originalYear) {
                $posterUrl = $this->searchPosterUrlByYear(
                    $originalTitle,
                    $originalTitle,
                    $originalYear,
                    $creators,
                    $language
                );
                if (null !== $posterUrl) {
                    return $posterUrl;
                }
            }
        }

        return null;
    }

    /**
     * Search in the movie database for a poster url by title, specific year and director.
     *
     * @param string $title
     *   The title of the item
     * @param string $originalTitle
     *   The original title of the item
     * @param int $originalYear
     *   The release year of the item
     * @param array $creators
     *   The creators of the movie
     * @param string $language
     *   The language of the movie
     *
     * @return string|null
     *   The poster url or null
     */
    private function searchPosterUrlByYear(
        string $title,
        string $originalTitle,
        int $originalYear,
        array $creators,
        string $language
    ): ?string {
        $posterUrl = null;

        // @see https://developers.themoviedb.org/3/movies/get-movie-images
        $query = [
            'query' => [
                'query' => $title,
                'year' => $originalYear,
                'api_key' => $this->apiKey,
                'language' => $language,
                'include_image_language' => 'en,null',
                'page' => '1',
                'include_adult' => 'false',
            ],
        ];

        try {
            $responseData = $this->sendRequest(self::SEARCH_URL, $query);

            $result = $this->getResultFromSet($responseData->results, $title, $originalTitle, $creators);

            if ($result) {
                $posterUrl = $this->getPosterUrl($result);
            }
        } catch (\Exception) {
            // Catch all exceptions to avoid crashing.
        }

        if (null === $posterUrl) {
            $d = 1;
        }

        return $posterUrl;
    }

    /**
     * Get the first match in the result set.
     *
     * @param array $tmdbResults
     *   Array of searxch results from The Movie Database (TMDB)
     * @param string $dwTitle
     *   The datawell title of the item
     * @param string|null $dwOriginalTitle
     *   The datawell original title of the item
     * @param array $dwCreators
     *   The datawell creators of the item
     *
     * @return \stdClass|null
     *   The matching result or null
     */
    private function getResultFromSet(array $tmdbResults, string $dwTitle, ?string $dwOriginalTitle, array $dwCreators): ?\stdClass
    {
        $dwOriginalTitle = $dwOriginalTitle ?? '';

        foreach ($tmdbResults as $tmdbResult) {
            // Validate title against result->title or result->original_title.
            // Minimum length of 3 for title contain check is risky - Think "The..."
            // We back it up by validating creators.
            if ($this->getTitlesHasMatch($tmdbResult->title, $tmdbResult->original_title, $dwTitle, $dwOriginalTitle, 0.8, 3)) {
                if ($this->validateCreators($tmdbResult->id, $dwCreators)) {
                    return $tmdbResult;
                }
            }
        }

        return null;
    }

    /**
     * Validate Datawell creators are found in the TMDB crew list.
     *
     * @see https://developers.themoviedb.org/3/movies/get-movie-credits
     *
     * @param int $tmdbId
     * @param array $dwCreators
     * @param float $relativeDistance
     *
     * @return bool
     */
    private function validateCreators(int $tmdbId, array $dwCreators, float $relativeDistance = 0.8): bool
    {
        $count = count($dwCreators);
        $found = 0;

        $queryUrl = 'https://api.themoviedb.org/3/movie/'.$tmdbId.'/credits';
        $responseData = $this->sendRequest($queryUrl);

        $tmdbCrew = [];
        $priorityJobs = ['Director', 'Screenplay'];
        foreach ($responseData->crew as $crewMember) {
            if (in_array($crewMember->job, $priorityJobs)) {
                $name = trim(mb_strtolower($crewMember->name, 'UTF-8'));
                $tmdbCrew[$name] = $name;
            }
        }

        // There can never be more matches than the lowest count of "$dwCreators" and "$tmdbCrew"
        $count = $count < count($tmdbCrew) ? $count : count($tmdbCrew);

        foreach ($dwCreators as $dwCreator) {
            foreach ($tmdbCrew as $crew) {
                $dwCreator = mb_strtolower($dwCreator, 'UTF-8');

                $dl = new DamerauLevenshtein($dwCreator, $crew);

                if ($crew === $dwCreator || $relativeDistance < $dl->getRelativeDistance()) {
                    ++$found;
                    break;
                }
            }
        }

        return $count > 0 && $count === $found;
    }

    /**
     * Check for match between the TMDB and Datawell titles.
     *
     * @param string $tmdbTitle
     * @param string $tmdbOrigTitle
     * @param string $dwTitle
     * @param string $dwOriginalTitle
     * @param float $relativeDistance
     * @param int $minLength
     *
     * @return bool
     */
    private function getTitlesHasMatch(string $tmdbTitle, string $tmdbOrigTitle, string $dwTitle, string $dwOriginalTitle, float $relativeDistance = 0.8, int $minLength = 5): bool
    {
        // Lowercase all strings for comparison.
        $tmdbTitle = mb_strtolower($tmdbTitle, 'UTF-8');
        $tmdbOrigTitle = mb_strtolower($tmdbOrigTitle, 'UTF-8');
        $dwTitle = mb_strtolower($dwTitle, 'UTF-8');
        $dwOriginalTitle = mb_strtolower($dwOriginalTitle, 'UTF-8');

        return $this->getTitleContained($tmdbTitle, $tmdbOrigTitle, $dwTitle, $dwOriginalTitle, $minLength)
            || $this->getTitlesMatchByDistance($tmdbTitle, $tmdbOrigTitle, $dwTitle, $dwOriginalTitle, $relativeDistance);
    }

    /**
     * Check if TMDB and Datawell titles match by distance.
     *
     * Uses relative distance of Damerau Levenshtein to determine if there
     * is a match between the datawell titles and the TMDB titles.
     *
     * @see https://en.wikipedia.org/wiki/Damerau%E2%80%93Levenshtein_distance
     *
     * @param string $tmdbTitle
     * @param string $tmdbOrigTitle
     * @param string $dwTitle
     * @param string $dwOriginalTitle
     * @param float $relativeDistance
     *
     * @return bool
     */
    private function getTitlesMatchByDistance(string $tmdbTitle, string $tmdbOrigTitle, string $dwTitle, string $dwOriginalTitle, float $relativeDistance): bool
    {
        $dls = [];
        $dls[] = new DamerauLevenshtein($tmdbTitle, $dwTitle);
        $dls[] = new DamerauLevenshtein($tmdbTitle, $dwOriginalTitle);
        $dls[] = new DamerauLevenshtein($tmdbOrigTitle, $dwTitle);
        $dls[] = new DamerauLevenshtein($tmdbOrigTitle, $dwOriginalTitle);

        foreach ($dls as $dl) {
            if ($relativeDistance < $dl->getRelativeDistance()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the Datawell title(s) are contained in the TMDB titles.
     *
     * The Datawell sometimes has a shorter title than TMDB. E.g.
     * "Heroes & Villains: Napoleon" vs. "Napoleon"
     *
     * @param string $tmdbTitle
     * @param string $tmdbOrigTitle
     * @param string $dwTitle
     * @param string $dwOriginalTitle
     * @param int $minLength
     *
     * @return bool
     */
    private function getTitleContained(string $tmdbTitle, string $tmdbOrigTitle, string $dwTitle, string $dwOriginalTitle, int $minLength): bool
    {
        $loop = [
            $tmdbTitle => [$dwTitle, $dwOriginalTitle],
            $tmdbOrigTitle => [$dwTitle, $dwOriginalTitle],
            $dwTitle => [$tmdbTitle, $tmdbOrigTitle],
            $dwOriginalTitle => [$tmdbTitle, $tmdbOrigTitle],
        ];

        foreach ($loop as $haystack => $needles) {
            foreach ($needles as $needle) {
                if (strlen($needle) >= $minLength) {
                    if (str_starts_with($haystack, $needle) || str_ends_with($haystack, $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get the poster url for a search result.
     *
     * @param \stdClass $result
     *   The result to create poster url from
     *
     * @return string|null
     *   The poster url or null
     */
    private function getPosterUrl(\stdClass $result): ?string
    {
        return !empty($result->poster_path) ? self::BASE_IMAGE_PATH.$result->poster_path : null;
    }

    /**
     * Send request to the movie database api.
     *
     * @param string $queryUrl
     *   The query url
     * @param array|null $query
     *   The query. Remember to add the api key to the query
     * @param string $method
     *   The request method
     *
     * @return \stdClass
     */
    private function sendRequest(string $queryUrl, array $query = null, string $method = 'GET'): \stdClass
    {
        // Default to always supplying the api key in the query.
        if (null === $query) {
            $query = [
                'query' => [
                    'api_key' => $this->apiKey,
                ],
            ];
        }

        try {
            // Send the request to The Movie Database.
            $response = $this->httpClient->request($method, $queryUrl, $query);

            // Respect api rate limits: https://developers.themoviedb.org/3/getting-started/request-rate-limiting
            // If 429 rate limit has been hit. Retry request after Retry-After.
            if (429 === $response->getStatusCode()) {
                $headers = $response->getHeaders();
                $retryAfterHeader = $headers['retry-after'];
                $retryAfterHeader = reset($retryAfterHeader);
                if (is_numeric($retryAfterHeader)) {
                    $retryAfter = (int) $retryAfterHeader;
                } else {
                    $retryAfter = (int) (new \DateTime((string) $retryAfterHeader))->format('U') - time();
                }

                // Rate limit hit. Wait until 'Retry-After' header, then retry.
                $this->logger->alert(sprintf('Rate limit hit. Sleeping for %d seconds', $retryAfter + 1));

                if ($retryAfter > 0) {
                    sleep($retryAfter);
                } else {
                    throw new \InvalidArgumentException('Sleep only accepts positive-int values');
                }

                // Retry request.
                $response = $this->httpClient->request($method, $queryUrl, $query);
            }

            // Get the response content.
            $content = $response->getContent();

            return json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|TransportExceptionInterface|\JsonException|\Exception $e) {
            return new \stdClass();
        }
    }
}
