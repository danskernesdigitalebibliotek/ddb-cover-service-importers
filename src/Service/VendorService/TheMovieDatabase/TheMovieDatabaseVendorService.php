<?php

/**
 * @file
 * Use a library's data well access to get movies.
 */

namespace App\Service\VendorService\TheMovieDatabase;

use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;

/**
 * Class TheMovieDatabaseVendorService.
 */
class TheMovieDatabaseVendorService extends AbstractDataWellVendorService
{
    protected const VENDOR_ID = 6;

    protected array $datawellQueries = [
        'phrase.type="blu-ray" and facet.typeCategory="film"',
        'phrase.type="dvd" and facet.typeCategory="film"',
    ];

    /**
     * TheMovieDatabaseVendorService constructor.
     *
     * @param DataWellClient $datawell
     * @param TheMovieDatabaseApiClient $api
     *   The movie api service
     */
    public function __construct(
        protected readonly DataWellClient $datawell,
        private readonly TheMovieDatabaseApiClient $api
    ) {
    }

    /**
     * {@inheritdoc}
     */
    protected function extractData(object $jsonContent): array
    {
        $data = [];

        foreach ($jsonContent['searchResponse']['result']['searchResult'] as $item) {
            foreach ($item['collection']['object'] as $object) {
                $pid = $object['identifier']['$'];
                $record = $object['record'];

                $title = array_key_exists('title', $record) ? $object['record']['title'][0]['$'] : null;
                $date = array_key_exists('date', $record) ? $object['record']['date'][0]['$'] : null;

                if ($title && $date) {
                    $description = array_key_exists('description', $record) ? $object['record']['description'] : null;
                    $originalYear = $this->getOriginalYear(array_column($description ?? [], '$'));
                    $creators = array_key_exists('creator', $record) ? $object['record']['creator'] : null;
                    $director = $this->getDirector($creators ?? []);

                    $posterUrl = $this->api->searchPosterUrl(title: $title, originalYear: $originalYear, director: $director);

                    $data[$pid] = $posterUrl;
                }
            }
        }

        return $data;
    }

    /**
     * Extract the original year from the descriptions.
     *
     * @param array $descriptions
     *   Search array of descriptions
     *
     * @return ?string The original year or null
     */
    private function getOriginalYear(array $descriptions): ?string
    {
        $matches = [];

        foreach ($descriptions as $description) {
            $descriptionMatches = [];
            $match = preg_match('/(\d{4})/u', (string) $description, $descriptionMatches);

            if ($match) {
                $matches = array_unique(array_merge($matches, $descriptionMatches));
            }
        }

        $upperYear = (int) date('Y') + 2;
        $confirmedMatches = [];

        foreach ($matches as $matchString) {
            $match = (int) $matchString;

            if ($match > 1850 && $match < $upperYear) {
                $confirmedMatches[] = $matchString;
            }
        }

        if (1 === count($confirmedMatches)) {
            return $confirmedMatches[0];
        }

        return null;
    }

    /**
     * Extract the director from the creators.
     *
     * @param array $creators
     *   Search array of creators
     *
     *   The director or null
     */
    private function getDirector(array $creators): ?string
    {
        $directors = [];

        foreach ($creators as $creator) {
            if (isset($creator['@type']['$']) && 'dkdcplus:drt' === $creator['@type']['$']) {
                if (isset($creator['$'])) {
                    $directors[] = $creator['$'];
                }
            }
        }

        // @TODO: Can there be more directors?
        if (count($directors) > 0) {
            return $directors[0];
        }

        return null;
    }
}
