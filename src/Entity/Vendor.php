<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="vendor",
 *    indexes={
 *        @ORM\Index(name="vendor_class_idx", columns={"class"}),
 *        @ORM\Index(name="vendor_name_idx", columns={"name"}),
 *        @ORM\Index(name="vendor_rank_idx", columns={"rank"})
 *    }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\VendorRepository")
 */
class Vendor
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue(strategy="NONE")
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    private string $class;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $name;

    /**
     * @ORM\Column(type="integer", unique=true)
     */
    private int $rank;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $imageServerURI;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $dataServerURI;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $dataServerUser;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $dataServerPassword;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Source", mappedBy="vendor", orphanRemoval=true)
     */
    private Collection $sources;

    public function __construct()
    {
        $this->sources = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the id.
     *
     * Id's must be manually set to match the 'VENDOR_ID' of the respective service class.
     *
     * @param int $id
     *
     * @return static
     */
    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * @return static
     */
    public function setClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    /**
     * @return static
     */
    public function setRank(int $rank): self
    {
        $this->rank = $rank;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return static
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getImageServerURI(): ?string
    {
        return $this->imageServerURI;
    }

    /**
     * @return static
     */
    public function setImageServerURI(string $imageServerURI): self
    {
        $this->imageServerURI = $imageServerURI;

        return $this;
    }

    public function getDataServerURI(): ?string
    {
        return $this->dataServerURI;
    }

    /**
     * @return static
     */
    public function setDataServerURI(string $dataServerURI): self
    {
        $this->dataServerURI = $dataServerURI;

        return $this;
    }

    public function getDataServerUser(): ?string
    {
        return $this->dataServerUser;
    }

    /**
     * @return static
     */
    public function setDataServerUser(string $dataServerUser): self
    {
        $this->dataServerUser = $dataServerUser;

        return $this;
    }

    public function getDataServerPassword(): ?string
    {
        return $this->dataServerPassword;
    }

    /**
     * @return static
     */
    public function setDataServerPassword(string $dataServerPassword): self
    {
        $this->dataServerPassword = $dataServerPassword;

        return $this;
    }

    /**
     * @return Collection
     */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    /**
     * @return static
     */
    public function addSource(Source $source): self
    {
        if (!$this->sources->contains($source)) {
            $this->sources[] = $source;
            $source->setVendor($this);
        }

        return $this;
    }

    /**
     * @return static
     */
    public function removeSource(Source $source): self
    {
        if ($this->sources->contains($source)) {
            $this->sources->removeElement($source);
            // set the owning side to null (unless already changed)
            if ($source->getVendor() === $this) {
                $source->setVendor(null);
            }
        }

        return $this;
    }
}
