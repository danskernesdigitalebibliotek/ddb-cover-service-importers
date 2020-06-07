<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Utils\CoverVendor\VendorImageItem;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Class VendorImageValidatorService.
 */
class VendorImageValidatorService
{
    private $httpClient;

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
     * Validate that remote image exists by sending a HTTP HEAD request.
     *
     * @param VendorImageItem $item
     *
     * @throws GuzzleException
     */
    public function validateRemoteImage(VendorImageItem $item): void
    {
        try {
            $head = $this->httpClient->request('HEAD', $item->getOriginalFile(), [
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
                $lastModified = \DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', time(), $timezone);
            }

            $item->setOriginalContentLength(array_shift($contentLengthArray));
            $item->setOriginalLastModified($lastModified);

            // Some images exists (return 200) but have no content
            $found = $item->getOriginalContentLength() > 0;
            $item->setFound($found);
        } catch (ClientException $exception) {
            $item->setFound(false);
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
