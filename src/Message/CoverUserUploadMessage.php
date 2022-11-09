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
    private ?string $agency = null;
    private ?string $profile = null;

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
     * @return CoverUserUploadMessage
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
     * @return CoverUserUploadMessage
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
     * @return CoverUserUploadMessage
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
     * @return CoverUserUploadMessage
     */
    public function setImageUrl(string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @param string $accrediting
     *
     * @return CoverUserUploadMessage
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
     * @return int|null
     */
    public function getVendorId(): ?int
    {
        return $this->vendorId;
    }

    /**
     * @param mixed $vendorId
     *
     * @return CoverUserUploadMessage
     */
    public function setVendorId(?int $vendorId): self
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    /**
     * Get agency id.
     *
     * This is an optional field that maybe used to change what agency is used during search.
     *
     * @return string
     *   Library agency id, if set else empty string.
     */
    public function getAgency(): string
    {
        return $this->agency ?? '';
    }

    /**
     * Set agency id
     *
     * @param string $agency
     *   Library agency id
     *
     * @return CoverUserUploadMessage
     */
    public function setAgency(string $agency): self
    {
        $this->agency = $agency;

        return $this;
    }

    /**
     * Get OpenPlatform search profile
     *
     * @return string
     */
    public function getProfile(): string
    {
        return $this->profile ?? '';
    }

    /**
     * @param string $profile
     *
     * @return CoverUserUploadMessage
     */
    public function setProfile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }
}
