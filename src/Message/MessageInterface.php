<?php

/**
 * @file
 */

namespace App\Message;

/**
 * Class ProcessMessage.
 */
interface MessageInterface
{
    public function getOperation();
    public function setOperation($operation): self;
    public function getIdentifierType();
    public function setIdentifierType($type): self;
    public function getIdentifier();
    public function setIdentifier($identifier): self;
    public function getVendorId();
    public function setVendorId($vendorId): self;
    public function getImageId();
    public function setImageId($imageId): self;
    public function useSearchCache(): ?bool;
    public function setUseSearchCache(bool $useIt): self;
}
