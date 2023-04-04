<?php

namespace App\Service\VendorService;

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
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface;
}
