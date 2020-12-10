<?php

/**
 * @file
 * Helper to generate unique id for logging (correlation id).
 */

namespace App\Service;

/**
 * Class RequestIdService
 */
class TraceIdService
{
    private static $id;

    /**
     * Get unique id.
     *
     * @param bool $refresh
     *
     * @return string
     *   The generated trace id
     *
     * @throws \Exception
     */
    public function get(bool $refresh = false): string
    {
        if (empty($this::$id) || $refresh) {
            $this->generate();
        }
        return $this::$id;
    }

    private function generate() {
        $this::$id = bin2hex(random_bytes(16));
    }
}

