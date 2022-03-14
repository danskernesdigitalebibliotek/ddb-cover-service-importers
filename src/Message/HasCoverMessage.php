<?php

namespace App\Message;

/**
 * Class HasCoverMessage.
 *
 * Used to send message about covers to DDF hasCover service at DBC.
 */
class HasCoverMessage
{
    private string $pid;
    private bool $coverExists;

    /**
     * @return string
     */
    public function getPid(): string
    {
        return $this->pid;
    }

    /**
     * @param string $pid
     *
     * @return $this
     */
    public function setPid(string $pid): self
    {
        $this->pid = $pid;

        return $this;
    }

    /**
     * @return bool
     */
    public function isCoverExists(): bool
    {
        return $this->coverExists;
    }

    /**
     * @param bool $coverExists
     *
     * @return $this
     */
    public function setCoverExists(bool $coverExists): self
    {
        $this->coverExists = $coverExists;

        return $this;
    }

}
