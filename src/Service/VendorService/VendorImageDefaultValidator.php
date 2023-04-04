<?php

namespace App\Service\VendorService;

use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class VendorImageValidatorService.
 */
class VendorImageDefaultValidator
{
    /**
     * VendorImageValidatorService constructor.
     *
     * @param HttpClientInterface $httpClient
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Validate that remote image exists by sending an HTTP HEAD request.
     *
     * @param VendorImageItem $item
     *
     * @return ResponseInterface
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_HEAD, $item->getOriginalFile());

            // Last Modified
            self::setLastModified($item, $response);

            // Content Length check - Some images exist (return 200) but have no content
            self::setContentLength($item, $response);

            // ETag
            self::setETag($item, $response);

            // Http success (2xx)
            $item->setFound(true);
        } catch (\Throwable $e) {
            // Http error (4xx/5xx)
            $item->setFound(false);
        } finally {
            return $response;
        }
    }

    /**
     * Set "lastModified" on Item from response headers. Fall back to "now" if header missing.
     *
     * @param VendorImageItem $item
     * @param ResponseInterface $response
     *
     * @return void
     *
     * @throws HttpExceptionInterface|TransportExceptionInterface
     */
    public static function setLastModified(VendorImageItem $item, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();

        $timezone = new \DateTimeZone('UTC');
        if (isset($headers['last-modified']) && !empty($headers['last-modified'])) {
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
    }

    /**
     * Set "contentLength" for Item.
     *
     * @param VendorImageItem $item
     * @param ResponseInterface $response
     *
     * @return void
     *
     * @throws HttpExceptionInterface|TransportExceptionInterface
     */
    public static function setContentLength(VendorImageItem $item, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();

        $contentLengthArray = [];
        if (isset($headers['content-length']) && !empty($headers['content-length'])) {
            $contentLengthArray = $headers['content-length'];
        }

        $item->setOriginalContentLength(array_shift($contentLengthArray));
    }

    /**
     * Set "ETag" for Item.
     *
     * @param VendorImageItem $item
     * @param ResponseInterface $response
     *
     * @return void
     *
     * @throws HttpExceptionInterface|TransportExceptionInterface
     */
    public static function setETag(VendorImageItem $item, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();

        if (isset($headers['etag']) && !empty($headers['etag'])) {
            $eTagArray = $headers['etag'];
            $item->setETag(array_shift($eTagArray));
        }
    }
}
