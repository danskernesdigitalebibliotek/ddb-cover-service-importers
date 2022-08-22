<?php

namespace App\Service;

use App\Exception\HasCoverException;
use App\Service\OpenPlatform\AuthenticationService;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class HasCoverService.
 */
class HasCoverService
{
    /**
     * @param HttpClientInterface $client
     * @param string $hasCoverServiceUrl
     * @param MetricsService $metricsService
     * @param AuthenticationService $authenticationService
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly bool $enabled,
        private readonly string $hasCoverServiceUrl,
        private readonly MetricsService $metricsService,
        private readonly AuthenticationService $authenticationService
    ) {
    }

    /**
     * Send request to external hasCover service.
     *
     * @param string $pid
     *   The datawell post id to set cover state for
     * @param bool $coverExists
     *   The cover state for the PID given
     *
     * @throws HasCoverException
     * @throws TransportExceptionInterface
     */
    public function post(string $pid, bool $coverExists): void
    {
        // Do not send request if service is enabled.
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'type' => 'hasCover',
        ];

        try {
            $response = $this->client->request('POST', $this->hasCoverServiceUrl, [
                'auth_bearer' => $this->authenticationService->getAccessToken(),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'pid' => $pid,
                    'coverExists' => $coverExists,
                ],
            ]);
        } catch (\Throwable $throwable) {
            $this->metricsService->counter('has_cover_error_total', 'Error with the request', 1, $labels);
            throw new HasCoverException('Unknown error communicating with the service');
        }

        switch ($response->getStatusCode()) {
            case 400:
                $this->metricsService->counter('has_cover_bad_requests_total', 'Bad request response', 1, $labels);
                throw new HasCoverException('Bad request to the service', 400);
            case 401:
                $this->metricsService->counter('has_cover_auth_error_total', 'Not authorized request', 1, $labels);
                throw new HasCoverException('Not authorized', 401);
            case 200:
                $this->metricsService->counter('has_cover_success_requests_total', 'Successful request sent', 1, $labels);
                break;

            default:
                $this->metricsService->counter('has_cover_unknown_total', 'Unknown response from service', 1, $labels);
                throw new HasCoverException('Unknown response from service', $response->getStatusCode());
        }

        $this->metricsService->counter('has_cover_requests_total', 'Successful request sent', 1, $labels);
    }
}
