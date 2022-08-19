<?php

/**
 * @file
 * Wrapper for information returned from vendor image host.
 */

namespace App\Utils\CoverVendor;

class UnverifiedVendorImageItem extends VendorImageItem
{
    private string $identifier;

    private string $identifierType;

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     */
    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    /**
     * @return string
     */
    public function getIdentifierType(): string
    {
        return $this->identifierType;
    }

    /**
     * @param string $identifierType
     */
    public function setIdentifierType(string $identifierType): void
    {
        $this->identifierType = $identifierType;
    }
}
