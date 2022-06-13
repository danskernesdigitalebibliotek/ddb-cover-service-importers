<?php

namespace App\Service\Indexing;

/**
 * @property string $password
 * @property string $username
 * @property int $id;
 * @property string $isIdentifier;
 * @property string $isType;
 * @property string $imageUrl;
 * @property string $imageFormat;
 * @property int $width;
 * @property int $height;
 */
interface IndexItemInterface
{
    public function getId(): int;

    public function setId(int $id): self;

    public function getIsIdentifier(): string;

    public function setIsIdentifier(string $isIdentifier): self;

    public function getIsType(): string;

    public function setIsType(string $isType): self;

    public function getImageUrl(): string;

    public function setImageUrl(string $imageUrl): self;

    public function getImageFormat(): string;

    public function setImageFormat(string $imageFormat): self;

    public function getWidth(): int;

    public function setWidth(int $width): self;

    public function getHeight(): int;

    public function setHeight(int $height): self;

}
