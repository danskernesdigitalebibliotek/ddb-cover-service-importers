<?php

/**
 * @file
 * The cover upload messages class.
 *
 * Please note that this class is shared between this repository and the cover upload service repository.
 */

namespace App\Message;

/**
 * Class CoverUploadMessage.
 */
class CoverUserUploadMessage
{
    private string $operation;
    private string $identifierType;
    private string $identifier;
    private string $imageUrl;
    private string $accrediting;
    private ?int $vendorId;
    private string $traceId;

    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @return static
     */
    public function setOperation(string $operation): self
    {
        $this->operation = $operation;

        return $this;
    }

    public function getIdentifierType(): string
    {
        return $this->identifierType;
    }

    /**
     * @return static
     */
    public function setIdentifierType(string $type): self
    {
        $this->identifierType = $type;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return static
     */
    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getImageUrl(): string
    {
        return $this->imageUrl;
    }

    /**
     * @return static
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @return static
     */
    public function setAccrediting(string $accrediting): self
    {
        $this->accrediting = $accrediting;

        return $this;
    }

    public function getAccrediting(): string
    {
        return $this->accrediting;
    }

    /**
     * @return ?int
     */
    public function getVendorId(): ?int
    {
        return $this->vendorId;
    }

    /**
     * @param ?int $vendorId
     *
     * @return static
     */
    public function setVendorId(?int $vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    /**
     * Get trace id (which is unique for the whole request).
     *
     *   The trace id
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Set trace id (which is unique for the whole request).
     *
     * @param string $traceId
     *   The trace id used to trace this message between services
     *
     * @return $this
     */
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }
}
