<?php

namespace App\Service\VendorService;

use App\Exception\UnknownVendorServiceException;
use App\Exception\UnsupportedIdentifierTypeException;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;

interface VendorServiceSingleIdentifierInterface
{
    /**
     * Get an unverified image item for the identifier of the given type.
     *
     * @param string $identifier
     *   The identifier
     * @param string $type
     *   The identifier type
     *
     * @return UnverifiedVendorImageItem
     *
     * @throws UnsupportedIdentifierTypeException
     * @throws UnknownVendorServiceException
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): UnverifiedVendorImageItem;

    /**
     * Does the vendor support this identifier type.
     *
     * @param string $type
     *   The identifier type
     *
     * @return bool
     *   Is the identifier type supported
     */
    public function supportsIdentifierType(string $type): bool;
}
