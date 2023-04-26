<?php

namespace App\Service\VendorService\ForsiderDk;

use App\Exception\ValidateRemoteImageException;
use App\Service\VendorService\VendorImageDefaultValidator;
use App\Service\VendorService\VendorImageValidatorInterface;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImageValidator implements VendorImageValidatorInterface
{
    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function supports(VendorImageItem $item): bool
    {
        return ForsiderDkVendorService::VENDOR_ID === $item->getVendor()->getId();
    }

    /**
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        $options = [
            'auth_basic' => [$this->username, $this->password],
        ];

        return $this->defaultValidator->validateRemoteImage($item, $options);
    }
}
