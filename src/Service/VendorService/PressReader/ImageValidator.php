<?php

namespace App\Service\VendorService\PressReader;

use App\Exception\ValidateRemoteImageException;
use App\Service\VendorService\VendorImageDefaultValidator;
use App\Service\VendorService\VendorImageValidatorInterface;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImageValidator implements VendorImageValidatorInterface
{
    private const MIN_IMAGE_SIZE = 40000;

    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator
    ) {
    }

    public function supports(VendorImageItem $item): bool
    {
        return PressReaderVendorService::VENDOR_ID === $item->getVendor()->getId();
    }

    /**
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        $response = $this->defaultValidator->validateRemoteImage($item);

        // The press reader CDN insert at special image saying that the content is not updated for newest news
        // cover. See https://i.prcdn.co/img?cid=9L09&page=1&width=1200, but the size will be under 40Kb, so we have
        // this extra test.
        if ($item->getOriginalContentLength() < self::MIN_IMAGE_SIZE) {
            $item->setFound(false);
        }

        return $response;
    }
}
