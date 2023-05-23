<?php

/**
 * @file
 * Logger processor adding trace id to log requests
 */

namespace App\Logger;

/**
 * Class TraceIdProcessor.
 */
class TraceIdProcessor
{
    /**
     * TraceIdProcessor constructor.
     *
     * @param string $traceId
     */
    public function __construct(
        private readonly string $traceId
    ) {
    }

    /**
     * Magic invoke function.
     *
     * @param array $record
     *   Log record
     *
     * @return array
     *   The record added require id in extras
     */
    public function __invoke(array $record): array
    {
        $record['extra']['traceId'] = $this->traceId;

        return $record;
    }
}
