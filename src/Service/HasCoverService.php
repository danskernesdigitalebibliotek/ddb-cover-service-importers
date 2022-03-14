<?php

namespace App\Service;

use App\Service\OpenPlatform\AuthenticationService;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class HasCoverService.
 */
class HasCoverService
{
    private HttpClientInterface $client;
    private string $hasCoverServiceUrl;
    private MetricsService $metricsService;
    private AuthenticationService $authenticationService;

    /**
     * @param HttpClientInterface $client
     * @param string $bindHasCoverServiceUrl
     * @param MetricsService $metricsService
     * @param AuthenticationService $authenticationService
     */
    public function __construct(HttpClientInterface $client, string $bindHasCoverServiceUrl, MetricsService $metricsService, AuthenticationService $authenticationService)
    {
        $this->client = $client;
        $this->hasCoverServiceUrl = $bindHasCoverServiceUrl;
        $this->metricsService = $metricsService;
        $this->authenticationService = $authenticationService;
    }

    /**
     * Send request to external hasCover service.
     *
     * @param string $pid
     *   The datawell post id to set cover state for
     * @param bool $coverExists
     *   The cover state for the PID given
     *
     * @return bool
     *   True if request was successful else false
     *
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function post(string $pid, bool $coverExists): bool
    {
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

            return false;
        }

        $status = false;
        switch ($response->getStatusCode()) {
            case 400:
                $this->metricsService->counter('has_cover_bad_requests_total', 'Bad request response', 1, $labels);
                break;

            case 401:
                $this->metricsService->counter('has_cover_auth_error_total', 'Not authorized request', 1, $labels);
                break;

            case 200:
                $status = true;
                $this->metricsService->counter('has_cover_success_requests_total', 'Successful request sent', 1, $labels);
                break;

            default:
                $this->metricsService->counter('has_cover_unknown_total', 'Unknown response from service', 1, $labels);
                break;
        }

        $this->metricsService->counter('has_cover_requests_total', 'Successful request sent', 1, $labels);

        return $status;
    }

    /**
     * Set PID to have a cover at the service.
     *
     * @param string $pid
     *   The PID to set to have a cover
     *
     * @return bool
     *   True if request was successful else false
     *
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function link(string $pid): bool
    {
        return $this->post($pid, true);
    }

    /**
     * Set PID to not having a cover at the service.
     *
     * @param string $pid
     *   The PID to set not having a cover
     *
     * @return bool
     *   True if request was successful else false
     *
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function unlink(string $pid): bool
    {
        return $this->post($pid, false);
    }
}
