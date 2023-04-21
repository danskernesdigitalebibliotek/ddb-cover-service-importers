<?php

namespace App\Service\VendorService;

use App\Exception\ValidateRemoteImageException;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class VendorImageValidatorService.
 */
interface VendorImageValidatorInterface
{
    /**
     * Is validation supported for Item.
     *
     * @param VendorImageItem $item
     *
     * @return bool
     */
    public function supports(VendorImageItem $item): bool;

    /**
     * Validate that remote image exists.
     *
     * @param VendorImageItem $item
     *
     * @return ResponseInterface
     *
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface;
}
