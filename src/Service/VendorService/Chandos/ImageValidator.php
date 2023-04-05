<?php

namespace App\Service\VendorService\Chandos;

use App\Exception\ValidateRemoteImageException;
use App\Service\VendorService\VendorImageDefaultValidator;
use App\Service\VendorService\VendorImageValidatorInterface;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImageValidator implements VendorImageValidatorInterface
{
    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator
    ) {
    }

    public function supports(VendorImageItem $item): bool
    {
        return ChandosVendorService::VENDOR_ID === $item->getVendor()->getId();
    }

    /**
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        $response = $this->defaultValidator->validateRemoteImage($item);

        try {
            $headers = $response->getHeaders();

            // Chandos CDN will respond with "200" and "text/html" for missing images
            if (isset($headers['content-type'])) {
                $contentType = array_pop($headers['content-type']);

                if (!str_starts_with($contentType, 'image')) {
                    $item->setFound(false);
                }
            }
        } catch (\Throwable $e) {
            $item->setFound(false);
        }

        return $response;
    }
}
