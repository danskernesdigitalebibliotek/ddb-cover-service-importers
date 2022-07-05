<?php

namespace App\Entity;

use App\Exception\UninitializedPropertyException;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="source",
 *    uniqueConstraints={
 *        @ORM\UniqueConstraint(name="vendor_unique",
 *            columns={"vendor_id", "match_id"})
 *    },
 *    indexes={
 *        @ORM\Index(name="is_type_vendor_idx", columns={"match_id", "match_type", "vendor_id"}),
 *        @ORM\Index(name="is_vendor_idx", columns={"match_id", "vendor_id"})
 *    }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\SourceRepository")
 */
class Source
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTimeInterface $date;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Vendor", inversedBy="sources")
     * @ORM\JoinColumn(nullable=false)
     */
    private ?Vendor $vendor;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private string $matchId;

    /**
     * @ORM\Column(type="string", length=25)
     */
    private string $matchType;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $originalFile;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $originalLastModified;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $originalContentLength;

    /**
     * @ORM\OneToOne(targetEntity="App\Entity\Image", inversedBy="source", cascade={"persist", "remove"})
     */
    private ?Image $image;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Search", mappedBy="source")
     */
    private Collection $searches;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private \DateTime $lastIndexed;

    public function getSearches(): Collection
    {
        return $this->searches;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getVendor(): Vendor
    {
        if (null === $this->vendor) {
            throw new UninitializedPropertyException('Vendor is not initialized');
        }

        return $this->vendor;
    }

    public function setVendor(?Vendor $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function getMatchId(): string
    {
        return $this->matchId;
    }

    public function setMatchId(string $matchId): self
    {
        $this->matchId = $matchId;

        return $this;
    }

    public function getMatchType(): string
    {
        return $this->matchType;
    }

    public function setMatchType(string $matchType): self
    {
        $this->matchType = $matchType;

        return $this;
    }

    public function getImage(): ?Image
    {
        return $this->image;
    }

    public function setImage(?Image $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function getOriginalFile(): string
    {
        if (null === $this->originalFile) {
            throw new UninitializedPropertyException('Original file is not initialized');
        }

        return $this->originalFile;
    }

    public function setOriginalFile(?string $originalFile): self
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    public function getOriginalLastModified(): ?\DateTime
    {
        return $this->originalLastModified;
    }

    public function setOriginalLastModified(?\DateTime $originalLastModified): self
    {
        $this->originalLastModified = $originalLastModified;

        return $this;
    }

    public function getOriginalContentLength(): ?int
    {
        return $this->originalContentLength;
    }

    public function setOriginalContentLength(?int $originalContentLength): self
    {
        $this->originalContentLength = $originalContentLength;

        return $this;
    }

    public function addSearch(Search $search): self
    {
        if (!$this->searches->contains($search)) {
            $this->searches[] = $search;
            $search->setSource($this);
        }

        return $this;
    }

    public function removeSearch(Search $search): self
    {
        if ($this->searches->contains($search)) {
            $this->searches->removeElement($search);
            // set the owning side to null (unless already changed)
            if ($search->getSource() === $this) {
                $search->setSource(null);
            }
        }

        return $this;
    }

    public function getLastIndexed(): ?\DateTime
    {
        return $this->lastIndexed;
    }

    public function setLastIndexed(\DateTime $lastIndexed): self
    {
        $this->lastIndexed = $lastIndexed;

        return $this;
    }
}
