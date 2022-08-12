<?php

/**
 * @file
 */

namespace App\Message;

/**
 * Class BaseMessage.
 */
abstract class AbstractBaseMessage
{
    private string $operation;
    private string $identifierType;
    private string $identifier;
    private int $vendorId;
    private ?int $imageId = null;
    private bool $useSearchCache = true;
    private ?string $traceId = null;

    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @return $this
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

    public function getVendorId(): int
    {
        return $this->vendorId;
    }

    /**
     * @return static
     */
    public function setVendorId(int $vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    public function getImageId(): ?int
    {
        return $this->imageId;
    }

    /**
     * @return static
     */
    public function setImageId(?int $imageId): self
    {
        $this->imageId = $imageId;

        return $this;
    }

    /**
     * Use search cache.
     *
     *   Defaults to true if not set
     */
    public function useSearchCache(): bool
    {
        return $this->useSearchCache;
    }

    /**
     * Should the search cache be used when processing the message.
     *
     * @param bool $useIt
     *   True to use or false to by-pass search cache
     *
     * @return static
     */
    public function setUseSearchCache(bool $useIt): self
    {
        $this->useSearchCache = $useIt;

        return $this;
    }

    /**
     * Get request id (which is unique for the whole request).
     *
     *   The request id
     */
    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    /**
     * Set trace id (which is unique for the whole request).
     *
     * @param string $traceId
     *   The trace id used to trace this message between services
     */
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }
}
