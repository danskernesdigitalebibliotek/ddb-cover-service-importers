<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class VendorImageValidatorService.
 */
class VendorImageValidatorService
{
    /**
     * VendorImageValidatorService constructor.
     *
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * Validate that remote image exists by sending an HTTP HEAD request.
     *
     * @param VendorImageItem $item
     * @param string $httpRequestMethod
     *
     * @return void
     */
    public function validateRemoteImage(VendorImageItem $item, string $httpRequestMethod = Request::METHOD_HEAD): void
    {
        try {
            $response = $this->httpClient->request($httpRequestMethod, $item->getOriginalFile());
            $headers = $response->getHeaders();

            // Last Modified
            $timezone = new \DateTimeZone('UTC');
            if (isset($headers['last-modified'])) {
                $lastModified = \DateTime::createFromFormat(
                    'D, d M Y H:i:s \G\M\T',
                    array_shift($headers['last-modified']),
                    $timezone
                );
            } else {
                // Not all server send last modified headers so fallback to now.
                $lastModified = new \DateTime('now', $timezone);
            }
            $item->setOriginalLastModified($lastModified);

            // Content Length check - Some images exist (return 200) but have no content
            // @TODO verify if this is still the case and document for which vendors
            $contentLengthArray = [];
            if (isset($headers['content-length']) && !empty($headers['content-length'])) {
                $contentLengthArray = $headers['content-length'];
            } elseif (array_key_exists('imagewidth', $headers)) {
                // This is a hack since image services such as flickr don't set content length header.
                // @TODO refactor so that custom checks can be configured per vendor
                $contentLengthArray = $headers['imagewidth'];
            } elseif (str_starts_with($item->getOriginalFile(), 'https://covers.openlibrary.org')) {
                // openlibrary.org returns correct http code but not ContentLength or
                // ImageWith headers so we need this hack for their covers to validate
                // @TODO refactor so that custom checks can be configured per vendor
                $contentLengthArray[] = 200 === $response->getStatusCode() ? 1 : 0;
            }

            $item->setOriginalContentLength(array_shift($contentLengthArray));

            $found = $item->getOriginalContentLength() > 0;
            $item->setFound($found);
        } catch (\Throwable $e) {
            // Some providers (i.e. Google Drive) disallows HEAD requests. Fall back
            // to GET request and try to validate image.
            if (405 === $e->getCode() && Request::METHOD_HEAD === $httpRequestMethod) {
                $this->validateRemoteImage($item, Request::METHOD_GET);
            } else {
                $item->setFound(false);
            }
        }
    }

    /**
     * Check if a remote image has been updated since we fetched the source.
     */
    public function isRemoteImageUpdated(VendorImageItem $item, Source $source): void
    {
        $this->validateRemoteImage($item);
        $item->setUpdated(false);

        if ($item->isFound()) {
            if ($item->getOriginalLastModified() != $source->getOriginalLastModified() ||
                $item->getOriginalContentLength() !== $source->getOriginalContentLength()) {
                $item->setUpdated(true);
            }
        }
    }

    /**
     * Fetch remote image header.
     *
     * @param string $header
     *   The header to fetch
     * @param string $url
     *   The image URL to query
     * @param string $httpRequestMethod
     *   The request method to use
     */
    public function remoteImageHeader(string $header, string $url, string $httpRequestMethod = Request::METHOD_HEAD): array
    {
        $headerContent = [];
        try {
            $response = $this->httpClient->request($httpRequestMethod, $url);
            $headers = $response->getHeaders();

            $headerContent = $headers[$header];
        } catch (\Throwable $e) {
            // Some providers (i.e. Google Drive) disallows HEAD requests. Fall back
            // to GET request and try to validate image.
            if (405 === $e->getCode() && Request::METHOD_HEAD === $httpRequestMethod) {
                $this->remoteImageHeader($header, Request::METHOD_GET);
            }
        }

        return $headerContent;
    }
}
