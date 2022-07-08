<?php

/**
 * @file
 * 'Publizon' vendor xml reader service
 */

namespace App\Service\VendorService\Publizon;

use App\Exception\XmlReaderException;

/**
 * Class PublizonXmlReaderService.
 */
class PublizonXmlReaderService
{
    private const AUTH_HEADER_NAME = 'x-service-key';

    private bool $isOpen = false;
    private \XMLReader $reader;

    /**
     * Open an XMLReader to the given endpoint.
     *
     * @param string $apiServiceKey
     * @param string $apiEndpoint
     *
     * @return true
     *
     * @throws XmlReaderException
     */
    public function open(string $apiServiceKey, string $apiEndpoint): bool
    {
        $param = ['http' => [
            'method' => 'GET',
            'header' => $this::AUTH_HEADER_NAME.': '.$apiServiceKey."\r\n",
        ]];
        libxml_set_streams_context(stream_context_create($param));
        $this->reader = new \XMLReader();

        if ($this->reader->open($apiEndpoint)) {
            $this->isOpen = true;

            return true;
        }

        throw new XmlReaderException('Unknown error when opening '.$apiEndpoint);
    }

    /**
     * Close the XML reader.
     *
     * @return bool
     *
     * @throws XmlReaderException
     */
    public function close(): bool
    {
        if ($this->isOpen) {
            return $this->reader->close();
        }

        throw new XmlReaderException();
    }

    /**
     * Move to next node in document.
     *
     * @return bool
     *
     * @throws XmlReaderException
     */
    public function read(): bool
    {
        if ($this->isOpen) {
            return $this->reader->read();
        }

        throw new XmlReaderException();
    }

    /**
     * Get the type of the current element.
     *
     * @return int
     *
     * @throws XmlReaderException
     */
    public function getNodeType(): int
    {
        if ($this->isOpen) {
            return $this->reader->nodeType;
        }

        throw new XmlReaderException();
    }

    /**
     * Get the name of the current element.
     *
     * @return string
     *
     * @throws XmlReaderException
     */
    public function getName(): string
    {
        if ($this->isOpen) {
            return $this->reader->name;
        }

        throw new XmlReaderException();
    }

    /**
     * Check if next element is end element of the given name. Advances the pointer one position.
     *
     * @param string $element
     *
     * @return bool
     *
     * @throws XmlReaderException
     */
    public function readUntilElementEnd(string $element): bool
    {
        if ($this->isOpen) {
            $this->reader->read();

            return $this->notAtElementEnd($element);
        }

        throw new XmlReaderException();
    }

    /**
     * Get the value of the next element. Advances the pointer one position.
     *
     * @return string
     *
     * @throws XmlReaderException
     */
    public function getNextElementValue(): string
    {
        if ($this->isOpen) {
            $this->reader->read();

            return $this->reader->value;
        }

        throw new XmlReaderException();
    }

    /**
     * Check if pointer is at an start element of the given name.
     *
     * @param string $elementName
     *
     * @return bool
     *
     * @throws XmlReaderException
     */
    public function isAtElementStart(string $elementName): bool
    {
        if ($this->isOpen) {
            return \XMLReader::ELEMENT === $this->reader->nodeType && $this->reader->name === $elementName;
        }

        throw new XmlReaderException();
    }

    /**
     * Check if pointer is at an end element of the given name.
     *
     * @param string $elementName
     *
     * @return bool
     *
     * @throws XmlReaderException
     */
    public function notAtElementEnd(string $elementName): bool
    {
        if ($this->isOpen) {
            return !(\XMLReader::END_ELEMENT === $this->reader->nodeType && $this->reader->name === $elementName);
        }

        throw new XmlReaderException();
    }
}
