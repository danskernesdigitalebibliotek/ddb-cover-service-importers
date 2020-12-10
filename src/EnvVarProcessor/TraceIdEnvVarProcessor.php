<?php

/**
 * @file
 * EnvProcessor to generate trace id.
 */

namespace App\EnvVarProcessor;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

class TraceIdEnvVarProcessor implements EnvVarProcessorInterface
{
    private static $id;

    /**
     * {@inheritdoc}
     */
    public function getEnv($prefix, $name, $getEnv)
    {
        $env = isset($_ENV[$name]) ? $getEnv($name) : '';
        if (empty($this::$id) || empty($env)) {
            $this->generate();
        } else {
            if (empty($this::$id)) {
                $this::$id = $env;
            }
        }

        return $this::$id;
    }

    /**
     * {@inheritdoc}
     */
    public static function getProvidedTypes()
    {
        return [
            'traceId' => 'string',
        ];
    }

    /**
     * @throws \Exception
     */
    private function generate() {
        $this::$id = bin2hex(random_bytes(16));
    }
}