<?php

/**
 * @file
 */

namespace App\Utils\Message;

/**
 * Class ProcessMessage.
 */
class CoverUploadProcessMessage implements \JsonSerializable
{
    private $operation;
    private $identifierType;
    private $identifier;
    private $imageUrl;
    private $accrediting;
    private $vendorId;

    /**
     * {@inheritdoc}
     *
     * Serialization function for the object.
     */
    public function jsonSerialize(): array
    {
        $arr = [];
        foreach ($this as $key => $value) {
            $arr[$key] = $value;
        }

        return $arr;
    }

    /**
     * @return string
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @param string $operation
     *
     * @return CoverUploadProcessMessage
     */
    public function setOperation(string $operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * @return string
     */
    public function getIdentifierType(): string
    {
        return $this->identifierType;
    }

    /**
     * @param string $type
     *
     * @return CoverUploadProcessMessage
     */
    public function setIdentifierType(string $type): self
    {
        $this->identifierType = $type;

        return $this;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     *
     * @return CoverUploadProcessMessage
     */
    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @return string
     */
    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    /**
     * @param string $imageUrl
     *
     * @return CoverUploadProcessMessage
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @param string $accrediting
     *
     * @return $this
     */
    public function setAccrediting(string $accrediting): self
    {
        $this->accrediting = $accrediting;

        return $this;
    }

    /**
     * @return string
     */
    public function getAccrediting(): string
    {
        return $this->accrediting;
    }

    /**
     * @return mixed
     */
    public function getVendorId()
    {
        return $this->vendorId;
    }

    /**
     * @param mixed $vendorId
     *
     * @return CoverUploadProcessMessage
     */
    public function setVendorId($vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }
}
