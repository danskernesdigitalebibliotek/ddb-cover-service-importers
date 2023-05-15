<?php

namespace App\Service\VendorService;

use App\Exception\UnknownVendorServiceException;
use App\Exception\UnsupportedIdentifierTypeException;

/**
 * Interface VendorServiceSingleIdentifierInterface.
 *
 * All single identifier vendors should implement this interface to ensure they are discovered by the system.
 */
interface VendorServiceSingleIdentifierInterface extends VendorServiceInterface
{
    /**
     * Get an unverified image item for the identifier of the given type.
     *
     * @param string $identifier
     *   The identifier
     * @param string $type
     *   The identifier type
     *
     * @return \Generator
     *
     * @throws UnsupportedIdentifierTypeException
     * @throws UnknownVendorServiceException
     */
    public function getUnverifiedVendorImageItems(string $identifier, string $type): \Generator;

    /**
     * Does the vendor support this identifier type.
     *
     * @param string $identifier
     *   The identifier
     * @param string $type
     *   The identifier type
     *
     * @return bool
     *   Is the identifier type supported
     */
    public function supportsIdentifier(string $identifier, string $type): bool;
}
