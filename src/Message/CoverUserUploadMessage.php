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
    private $operation;
    private $identifierType;
    private $identifier;
    private $imageUrl;
    private $accrediting;
    private $vendorId;
    private $traceId;

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
     * @return static
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
     * @return static
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
     * @return static
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
     * @return static
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @param string $accrediting
     *
     * @return static
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
     * @return static
     */
    public function setVendorId($vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    /**
     * Get trace id (which is unique for the whole request).
     *
     * @return string
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
