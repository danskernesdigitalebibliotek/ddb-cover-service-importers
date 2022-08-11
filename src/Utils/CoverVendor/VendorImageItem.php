<?php

/**
 * @file
 * Wrapper for information returned from vendor image host.
 */

namespace App\Utils\CoverVendor;

class VendorImageItem implements \Stringable
{
    private bool $found = false;
    private bool $updated = false;
    private string $vendor;
    private string $originalFile;
    private \DateTime $originalLastModified;
    private int $originalContentLength;

    public function __toString(): string
    {
        $output = [];

        $output[] = str_repeat('-', 42);
        $output[] = 'Vendor: '.$this->getVendor();
        $output[] = 'Original file: '.$this->getOriginalFile();
        $output[] = str_repeat('-', 42);

        return implode("\n", $output);
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    /**
     * @return static
     */
    public function setFound(bool $found): self
    {
        $this->found = $found;

        return $this;
    }

    public function isUpdated(): bool
    {
        return $this->updated;
    }

    /**
     * @return static
     */
    public function setUpdated(bool $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function getVendor(): string
    {
        return $this->vendor;
    }

    /**
     * @param $vendor
     *
     * @return static
     */
    public function setVendor($vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getOriginalFile(): string
    {
        return $this->originalFile;
    }

    /**
     * @return static
     */
    public function setOriginalFile(string $originalFile): self
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getOriginalLastModified(): ?\DateTime
    {
        return $this->originalLastModified;
    }

    /**
     * @param mixed $originalLastModified
     *
     * @return static
     */
    public function setOriginalLastModified(?\DateTime $originalLastModified): self
    {
        $this->originalLastModified = $originalLastModified;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getOriginalContentLength(): ?int
    {
        return $this->originalContentLength;
    }

    /**
     * @param mixed $originalContentLength
     *
     * @return static
     */
    public function setOriginalContentLength(?int $originalContentLength): self
    {
        $this->originalContentLength = $originalContentLength;

        return $this;
    }
}
