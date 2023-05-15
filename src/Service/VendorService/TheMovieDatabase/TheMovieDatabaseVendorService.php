<?php

/**
 * @file
 * Use a library's data well access to get movies.
 */

namespace App\Service\VendorService\TheMovieDatabase;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;
use PrinsFrank\Standards\Language\ISO639_2_Alpha_3_Common;

/**
 * Class TheMovieDatabaseVendorService.
 */
class TheMovieDatabaseVendorService extends AbstractDataWellVendorService implements VendorServiceSingleIdentifierInterface
{
    public const VENDOR_ID = 6;

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
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItems(string $identifier, string $type): \Generator
    {
        if (!$this->supportsIdentifier($identifier, $type)) {
            throw new UnsupportedIdentifierTypeException(\sprintf('Unsupported single identifier: %s (%s)', $identifier, $type));
        }

        $datawellQuery = 'rec.id='.$identifier;
        [$jsonContent, $more, $offset] = $this->datawell->search($datawellQuery, 0);

        // This will only query TMDB if "getWorkType" returns "movie"
        $pidArray = $this->extractData($jsonContent);

        if (array_key_exists($identifier, $pidArray) && null !== $pidArray[$identifier]) {
            $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

            $item = new UnverifiedVendorImageItem($pidArray[$identifier], $vendor);
            $item->setIdentifier($identifier);
            $item->setIdentifierType($type);

            yield $item;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifier(string $identifier, string $type): bool
    {
        return IdentifierType::PID === $type;
    }

    /**
     * {@inheritdoc}
     */
    protected function extractData(object $jsonContent): array
    {
        $data = [];

        if (isset($jsonContent->searchResponse?->result?->searchResult)) {
            foreach ($jsonContent->searchResponse?->result?->searchResult ?? [] as $searchResult) {
                foreach ($searchResult->collection?->object ?? [] as $object) {
                    $pid = $object->identifier->{'$'};
                    $record = $object->record;

                    // Match logic currently doesn't handle TV-shows
                    $workType = property_exists($record, 'subject') ? $this->getWorkType($record->subject) : WorkType::UNKNOWN;
                    if (WorkType::MOVIE === $workType) {
                        $title = property_exists($record, 'title') ? $record->title[0]->{'$'} : null;
                        $title = $title ? $this->getSanitizedTitle($title) : null;

                        $originalTitle = property_exists($record, 'alternative') ? $record->alternative[0]->{'$'} : '';

                        $date = property_exists($record, 'date') ? $record->date[0]->{'$'} : null;

                        if ($title && $date) {
                            $descriptions = property_exists($record, 'description') ? $record->description : [];
                            $subjects = property_exists($record, 'subject') ? $record->subject : [];

                            // Year must be extracted from descriptions. Datawell "date" is the
                            // disc publication date. Not the movie release year.
                            $originalYear = $this->getOriginalYear($descriptions, $subjects);

                            // Datawell year can be "of by one" in either direction relative
                            // to TMDB. Go figure ...
                            $originalYears = (null !== $originalYear) ? [
                                $originalYear,
                                $originalYear - 1,
                                $originalYear + 1,
                            ] : [];

                            $creators = property_exists($record, 'creator') ? $record->creator : [];
                            $creators = $this->getCreators($creators);

                            $languages = property_exists($record, 'language') ? $record->language : [];
                            $audioLanguages = $this->getAudioLanguages($languages);
                            $subtitleLanguages = $this->getSubtitleLanguages($languages);
                            $searchLanguage = $this->getSearchLanguage($audioLanguages, $subtitleLanguages);

                            $posterUrl = $this->api->searchPosterUrl(
                                title: $title,
                                originalTitle: $originalTitle,
                                originalYears: $originalYears,
                                creators: $creators,
                                language: $searchLanguage,
                            );

                            $data[$pid] = $posterUrl;
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Guess the work type (movie or tv-show) based on the datawell result.
     *
     * @TODO Currently has high number of tv-show that gets marked as movies.
     *
     * @param array $subjects
     *
     * @return WorkType
     */
    private function getWorkType(array $subjects): WorkType
    {
        $searchDkcdPlus = ['dkdcplus:DK5-Text', 'dkdcplus:DBCS', 'dkdcplus:genre'];

        $types = [];

        foreach ($subjects as $subject) {
            if (property_exists($subject, '@type')) {
                if (in_array($subject->{'@type'}->{'$'}, $searchDkcdPlus)) {
                    $types[] = mb_strtolower($subject->{'$'});
                }

                if ('dcterms:LCSH' === $subject->{'@type'}->{'$'}) {
                    if (str_ends_with($subject->{'$'}, ' films')) {
                        $types[] = 'spillefilm';
                    }
                }
            }
        }

        // Order is important. A TV Show will also have the
        // subject "Spillefilm" but a movie will not have the
        // subject "tv-serier".
        // @TODO match by title also and look for "Disc, Episode, etc"?
        if (in_array('tv-serier', $types)) {
            return WorkType::TV_SHOW;
        }

        if (in_array('spillefilm', $types)) {
            return WorkType::MOVIE;
        }

        return WorkType::UNKNOWN;
    }

    /**
     * Get the search language.
     *
     * If the result has danish as either audio- or subtitle language we assume
     * it is an edition for the danish marked and search for a danish cover. Else
     * we default to the first audio, first subtitle then english language.
     *
     * @param array $audioLanguages
     * @param array $subtitleLanguages
     *
     * @return string
     */
    private function getSearchLanguage(array $audioLanguages, array $subtitleLanguages): string
    {
        $audioLanguages = array_map('mb_strtolower', $audioLanguages);
        $subtitleLanguages = array_map('mb_strtolower', $subtitleLanguages);

        if (in_array('da', $audioLanguages) || in_array('dansk', $subtitleLanguages)) {
            return 'da';
        } elseif (!empty($audioLanguages)) {
            return $audioLanguages[0];
        } elseif (!empty($subtitleLanguages)) {
            return $subtitleLanguages[0];
        } else {
            return 'en';
        }
    }

    /**
     * Get audio language codes from result.
     *
     * @param array $languages
     *
     * @return string[]
     */
    private function getAudioLanguages(array $languages): array
    {
        $audioLanguages = [];

        // Get all audio languages from type "dcterms:ISO639-2"
        foreach ($languages as $language) {
            if (isset($language->{'@type'}?->{'$'}) && 'dcterms:ISO639-2' === $language->{'@type'}?->{'$'}) {
                if (isset($language->{'$'})) {
                    // Translate language code from "xyz" to "xy", e.g. "dan" to "da"
                    $lang = ISO639_2_Alpha_3_Common::tryFrom($language->{'$'})?->toISO639_1_Alpha_2()?->value;
                    if (null !== $lang) {
                        $audioLanguages[] = $lang;
                    }
                }
            }
        }

        return $audioLanguages;
    }

    /**
     * Get subtitle language codes from result.
     *
     * @param array $languages
     *
     * @return string[]
     */
    private function getSubtitleLanguages(array $languages): array
    {
        $subtitleLanguages = [];

        // Get all subtitle languages from type "dkdcplus:subtitles"
        foreach ($languages as $language) {
            if (isset($language->{'@type'}?->{'$'}) && 'dkdcplus:subtitles' === $language->{'@type'}?->{'$'}) {
                if (isset($language->{'$'})) {
                    $subtitleLanguages[] = $language->{'$'};
                }
            }
        }

        return $subtitleLanguages;
    }

    /**
     * Extract the original year from the descriptions.
     *
     * The descriptions follow the format "Bla, bal, bla 1984", so we find all
     * descriptions that end in a 4-digit number. Then pick the lowest e.g. the
     * chronologically first. We can't use the "date" field from the result
     * because this refers to the data added in the data well.
     *
     * @param array $descriptions
     *   Search array of descriptions
     *
     * @return ?int The original year or null
     */
    private function getOriginalYear(array $descriptions, array $subjects): ?int
    {
        $matches = [];

        foreach (array_merge($descriptions, $subjects) as $subject) {
            $subjectMatches = [];
            $match = preg_match('/(\d{4})$/u', (string) $subject->{'$'}, $subjectMatches);

            if ($match) {
                $matches = array_unique(array_merge($matches, $subjectMatches));
            }
        }

        $upperYear = (int) date('Y') + 2;
        $confirmedMatches = [];

        foreach ($matches as $matchString) {
            $match = intval($matchString);

            // "Roundhay Garden Scene ... is believed to be the oldest surviving film in existence."
            // https://en.wikipedia.org/wiki/Roundhay_Garden_Scene
            if ($match > 1888 && $match < $upperYear) {
                $confirmedMatches[] = $match;
            }
        }

        // Ensure matches are ordered chronologically
        sort($confirmedMatches);

        return $confirmedMatches[0] ?? null;
    }

    /**
     * Extract the creators from the datawell creators excluding sort entries.
     *
     * @param array $creators
     *   Search array of creators
     *
     * @return array
     *   Array of sanitized creator names excluding "oss:sort" duplicates or empty array if none found
     */
    private function getCreators(array $creators): array
    {
        $filtered = [];

        foreach ($creators as $creator) {
            // The "oss:sort" is a duplicate entry for sorting. E.g " Koster, Henry" for "Henry Koster"
            if (isset($creator->{'$'})) {
                if (!isset($creator->{'@type'}?->{'$'}) || (isset($creator->{'@type'}?->{'$'}) && 'oss:sort' !== $creator->{'@type'}?->{'$'})) {
                    $name = $this->getSanitizedCreator($creator->{'$'});
                    $filtered[$name] = $name;
                }
            }
        }

        return $filtered;
    }

    /**
     * Get the sanitized title without additional text added by the datawell.
     *
     * @param string $datawellTitle
     *   The title to sanitize
     *
     * @return string
     *   The sanitized title
     */
    private function getSanitizedTitle(string $datawellTitle): string
    {
        // E.g. "Game of thrones" for "Game of thrones. Sæson 2. Disc 3, episodes 5, 6 & 7".
        $pos = mb_stripos($datawellTitle, '. Sæson', 0, 'UTF-8');
        if ($pos > 0) {
            $datawellTitle = mb_substr($datawellTitle, 0, $pos, 'UTF-8');
        }

        return trim($datawellTitle);
    }

    /**
     * Get the sanitized creator name without additional text added by the datawell.
     *
     * @param string $datawellName
     *   The name to sanitize
     *
     * @return string
     *   The sanitized name
     */
    private function getSanitizedCreator(string $datawellName): string
    {
        // E.g. "Henry Koster" for "Henry Koster (1905-1988)".
        // E.g. "Elizabeth Sellars" for "Elizabeth Sellars (1923-)"
        // E.g. "George Miller" for "George Miller (1945 March 3-)"
        // E.g. "Margaret Mitchell" for "Margaret Mitchell (f. 1900)"
        $datawellName = preg_replace('/(\(.*\d{4}).*\)$/', '', $datawellName);

        return trim($datawellName);
    }
}
