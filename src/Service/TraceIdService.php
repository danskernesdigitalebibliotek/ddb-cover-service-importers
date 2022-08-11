<?php

/**
 * @file
 * Helper to generate unique id for logging (correlation id).
 */

namespace App\Service;

/**
 * Class RequestIdService.
 */
class TraceIdService
{
    private static string $id;

    /**
     * Get unique id.
     *
     * @param bool $refresh
     *
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

    /**
     * Generate uniq id.
     *
     * @throws \Exception
     */
    private function generate(): void
    {
        $this::$id = bin2hex(random_bytes(16));
    }
}
