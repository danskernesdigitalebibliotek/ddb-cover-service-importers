<?php

namespace App\Entity;

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
    private Vendor $vendor;

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

    /**
     * @return Collection
     */
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

    /**
     * @return static
     */
    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    /**
     * @return Vendor
     */
    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    /**
     * @return static
     */
    public function setVendor(?Vendor $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }

    /**
     * @return string
     */
    public function getMatchId(): string
    {
        return $this->matchId;
    }

    /**
     * @return static
     */
    public function setMatchId(string $matchId): self
    {
        $this->matchId = $matchId;

        return $this;
    }

    /**
     * @return string
     */
    public function getMatchType(): string
    {
        return $this->matchType;
    }

    /**
     * @return static
     */
    public function setMatchType(string $matchType): self
    {
        $this->matchType = $matchType;

        return $this;
    }

    /**
     * @return Image|null
     */
    public function getImage(): ?Image
    {
        return $this->image;
    }

    /**
     * @return static
     */
    public function setImage(?Image $image): self
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getOriginalFile(): ?string
    {
        return $this->originalFile;
    }

    /**
     * @return static
     */
    public function setOriginalFile(?string $originalFile): self
    {
        $this->originalFile = $originalFile;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getOriginalLastModified(): ?\DateTime
    {
        return $this->originalLastModified;
    }

    /**
     * @return static
     */
    public function setOriginalLastModified(?\DateTime $originalLastModified): self
    {
        $this->originalLastModified = $originalLastModified;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getOriginalContentLength(): ?int
    {
        return $this->originalContentLength;
    }

    /**
     * @return static
     */
    public function setOriginalContentLength(?int $originalContentLength): self
    {
        $this->originalContentLength = $originalContentLength;

        return $this;
    }

    /**
     * @return static
     */
    public function addSearch(Search $search): self
    {
        if (!$this->searches->contains($search)) {
            $this->searches[] = $search;
            $search->setSource($this);
        }

        return $this;
    }

    /**
     * @return static
     */
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

    /**
     * @return \DateTime
     */
    public function getLastIndexed(): ?\DateTime
    {
        return $this->lastIndexed;
    }

    /**
     * @param \DateTime $lastIndexed
     *
     * @return $this
     */
    public function setLastIndexed(\DateTime $lastIndexed): self
    {
        $this->lastIndexed = $lastIndexed;

        return $this;
    }
}
