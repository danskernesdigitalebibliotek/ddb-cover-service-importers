<?php

/**
 * @file
 * EnvProcessor to generate trace id.
 */

namespace App\EnvVarProcessor;

use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

class TraceIdEnvVarProcessor implements EnvVarProcessorInterface
{
    private static string $id;

    /**
     * {@inheritdoc}
     */
    public function getEnv($prefix, $name, $getEnv): string
    {
        try {
            $this::$id = $getEnv($name);
        } catch (EnvNotFoundException $exception) {
            // Do not do anything here as the id will fall back to be generated.
        }

        if (empty($this::$id)) {
            $this->generate();
        }

        return $this::$id;
    }

    /**
     * {@inheritdoc}
     */
    #[ArrayShape(['traceId' => 'string'])]
    public static function getProvidedTypes(): array
    {
        return [
            'traceId' => 'string',
        ];
    }

    /**
     * Generate new unique id.
     *
     * @throws \Exception
     *
     * @return void
     */
    private function generate(): void
    {
        $this::$id = bin2hex(random_bytes(16));
    }
}
