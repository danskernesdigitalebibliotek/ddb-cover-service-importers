<?php

/**
 * @file
 * Logger processor adding trace id to log requests
 */

namespace App\Logger;

use Monolog\LogRecord;

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
     * @param LogRecord $record
     *   LogRecord
     *
     * @return LogRecord
     *   The record added require id in extras
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['traceId'] = $this->traceId;

        return $record;
    }
}
