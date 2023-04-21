<?php

namespace App\Service\VendorService\Saxo;

use App\Exception\ValidateRemoteImageException;
use App\Service\VendorService\VendorImageDefaultValidator;
use App\Service\VendorService\VendorImageValidatorInterface;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImageValidator implements VendorImageValidatorInterface
{
    private const MIN_IMAGE_SIZE = 7000;

    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator
    ) {
    }

    public function supports(VendorImageItem $item): bool
    {
        return SaxoVendorService::VENDOR_ID === $item->getVendor()->getId();
    }

    /**
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        $response = $this->defaultValidator->validateRemoteImage($item);

        // Saxo CDN replieds with HTTP 200 and small default images. E.g.
        // https://imgcdn.saxo.com/_9788791977339/0x0 (length: 3494)
        // https://imgcdn.saxo.com/_9788773327395/0x0 (length: 6944)
        if ($item->getOriginalContentLength() < self::MIN_IMAGE_SIZE) {
            $item->setFound(false);
        }

        return $response;
    }
}
