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
    private string $traceId;

    /**
     * TraceIdProcessor constructor.
     *
     * @param string $bindTraceId
     */
    public function __construct(string $bindTraceId)
    {
        $this->traceId = $bindTraceId;
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
