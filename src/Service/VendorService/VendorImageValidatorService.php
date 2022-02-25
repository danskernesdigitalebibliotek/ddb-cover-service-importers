<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Utils\CoverVendor\VendorImageItem;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class VendorImageValidatorService.
 */
class VendorImageValidatorService
{
    private ClientInterface $httpClient;

    /**
     * VendorImageValidatorService constructor.
     *
     * @param ClientInterface $httpClient
     */
    public function __construct(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Validate that remote image exists by sending an HTTP HEAD request.
     *
     * @param VendorImageItem $item
     */
    public function validateRemoteImage(VendorImageItem $item, string $httpRequestMethod = Request::METHOD_HEAD): void
    {
        try {
            $head = $this->httpClient->request($httpRequestMethod, $item->getOriginalFile(), [
                'allow_redirects' => [
                    'strict' => true,   // use "strict" RFC compliant redirects to avoid 30x redirects resulting in GET calls
                ],
            ]);

            $contentLengthArray = $head->getHeader('Content-Length');
            $lastModifiedArray = $head->getHeader('Last-Modified');

            $timezone = new \DateTimeZone('UTC');
            if (empty($lastModifiedArray)) {
                // Not all server send last modified headers so fallback to now.
                $lastModified = new \DateTime('now', $timezone);
            } else {
                $lastModified = \DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', array_shift($lastModifiedArray), $timezone);
            }

            $item->setOriginalContentLength(array_shift($contentLengthArray));
            $item->setOriginalLastModified($lastModified);

            // Some images exist (return 200) but have no content
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
     *
     * @param VendorImageItem $item
     * @param Source $source
     *
     * @throws GuzzleException
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
}
