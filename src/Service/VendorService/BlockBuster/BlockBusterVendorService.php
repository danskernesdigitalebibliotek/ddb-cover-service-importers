<?php

/**
 * @file
 * Use a library's data well access to get comic+ covers.
 */

namespace App\Service\VendorService\BlockBuster;

use App\Service\VendorService\AbstractDataWellVendorService;

/**
 * Class ComicsPlusVendorService.
 */
class BlockBusterVendorService extends AbstractDataWellVendorService
{
    public const VENDOR_ID = 21;
    protected const DATAWELL_URL_RELATION = 'dbcaddi:hasImage';

    protected array $datawellQueries = ['term.acSource="Bibliotekernes filmtjeneste"'];

    private array $imagePatternPrioritized = ['po-reg-superhigh', 'po-reg-high'];

    /**
     * {@inheritdoc}
     */
    protected function extractData(object $jsonContent): array
    {
        return $this->extractCoverUrl($jsonContent);
    }

    /**
     * Helper function to find urls based on the prioritized pattern array.
     *
     * @param array $urls
     *   Array of urls to filter/search
     *
     * @return false|mixed
     *   Found URL or first URL in array
     */
    private function filterUrlsPrioritized(array $urls)
    {
        foreach ($this->imagePatternPrioritized as $pattern) {
            foreach ($urls as $url) {
                if (str_contains($url, $pattern)) {
                    return $url;
                }
            }
        }

        return reset($urls);
    }

    /**
     * Extract PIDs and matching cover urls from result set.
     *
     * Almost same function as in the datawell service, just prioritized by url patterns.
     *
     * @param object $jsonContent
     *   Array of the json decoded data
     *   The datawell relation key that holds the cover URL
     *
     * @return array<string, ?string>
     *   Array of all pid => url pairs found in response
     */
    private function extractCoverUrl(object $jsonContent): array
    {
        $data = [];

        if (isset($jsonContent->searchResponse?->result?->searchResult)) {
            foreach ($jsonContent->searchResponse->result->searchResult as $searchResult) {
                foreach ($searchResult->collection?->object ?? [] as $object) {
                    $pid = $object->identifier?->{'$'};
                    if (null !== $pid) {
                        $data[$pid] = null;
                        $coversUrls = [];
                        foreach ($object->relations?->relation ?? [] as $relation) {
                            if (self::DATAWELL_URL_RELATION === $relation->relationType?->{'$'}) {
                                $coverUrl = $relation->relationUri?->{'$'};
                                $coversUrls[] = (string) $coverUrl;
                            }
                        }

                        // Filter urls based on prioritized array.
                        if (!empty($coversUrls)) {
                            $data[$pid] = $this->filterUrlsPrioritized($coversUrls);
                        }
                    }
                }
            }
        }

        return $data;
    }
}
