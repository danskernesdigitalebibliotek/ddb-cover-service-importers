<?php

namespace App\Service\VendorService\EbookCentral;

use App\Exception\ValidateRemoteImageException;
use App\Service\VendorService\VendorImageDefaultValidator;
use App\Service\VendorService\VendorImageValidatorInterface;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImageValidator implements VendorImageValidatorInterface
{
    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator,
    ) {
    }

    public function supports(VendorImageItem $item): bool
    {
        return EbookCentralVendorService::VENDOR_ID === $item->getVendor()->getId();
    }

    /**
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        $response = $this->defaultValidator->validateRemoteImage($item);

        try {
            $headers = $response->getHeaders();
        } catch (TransportExceptionInterface|HttpExceptionInterface $e) {
            throw new ValidateRemoteImageException($e->getMessage(), $e->getCode(), $e);
        }

        // This vendor returns an HTML page say there is no image. So we need to check that an image is found.
        if (!str_starts_with($headers['content-type'][0], 'image')) {
            $item->setOriginalContentLength(null);
            $item->setFound(false);
        }

        return $response;
    }
}
