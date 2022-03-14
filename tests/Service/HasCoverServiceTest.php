<?php

/**
 * @file
 * Test cases for the Open Platform authentication service.
 */

namespace Tests\Service;

use App\Exception\HasCoverException;
use App\Service\HasCoverService;
use App\Service\OpenPlatform\AuthenticationService;
use ItkDev\MetricsBundle\Service\MetricsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Class HasCoverServiceTest.
 */
class HasCoverServiceTest extends TestCase
{
    public const TOKEN = 'fde1432d66d33e4cq66e5ad04757811e47864329';

    /**
     * Test the behaviour of the hasCovers service.
     */
    public function testHasCover()
    {
        $pid = '870970-basis:28718896';

        /** @var MockResponse[] $responses */
        [$client, $responses] = $this->getApiHttpMock();
        $hasCover = new HasCoverService($client, 'https://test.itkdev.dk/api/v2', $this->getMetricsService(), $this->getAuthenticationService());

        try {
            $hasCover->post($pid, false);
            $this->assertEquals(200, $responses[0]->getStatusCode());
            $this->assertEquals('POST', $responses[0]->getRequestMethod());
            $this->assertEquals('{"pid":"870970-basis:28718896","coverExists":false}', $responses[0]->getRequestOptions()['body']);
        } catch (HasCoverException $e) {
            $this->fail('HasCoverException thrown');
        }

        try {
            $hasCover->post($pid, true);
            $this->assertEquals(200, $responses[1]->getStatusCode());
            $this->assertEquals('POST', $responses[1]->getRequestMethod());
            $this->assertEquals('{"pid":"870970-basis:28718896","coverExists":true}', $responses[1]->getRequestOptions()['body']);
        } catch (HasCoverException $e) {
            $this->fail('HasCoverException thrown');
        }

        try {
            $hasCover->post($pid, false);
            $this->fail('HasCoverException was not thrown');
        } catch (HasCoverException $e) {
            $this->assertEquals(400, $e->getCode());
            $this->assertEquals('Bad request to the service', $e->getMessage());
        }

        try {
            $hasCover->post($pid, false);
            $this->fail('HasCoverException was not thrown');
        } catch (HasCoverException $e) {
            $this->assertEquals(401, $e->getCode());
            $this->assertEquals('Not authorized', $e->getMessage());
        }

        try {
            $hasCover->post($pid, false);
            $this->fail('HasCoverException was not thrown');
        } catch (HasCoverException $e) {
            $this->assertEquals(500, $e->getCode());
            $this->assertEquals('Unknown response from service', $e->getMessage());
        }
    }

    /**
     * Mock http client for communication with the hasCover service.
     *
     * @return array
     *   The Client and the MockResponse
     */
    private function getApiHttpMock(): array
    {
        $mockResponses = [
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 400]),
            new MockResponse('', ['http_code' => 401]),
            new MockResponse('', ['http_code' => 500]),
        ];

        return [new MockHttpClient($mockResponses), $mockResponses];
    }

    /**
     * Mock adgangsplatformen authentication.
     *
     * @return AuthenticationService
     *   Mocked Authentication Service
     */
    private function getAuthenticationService(): AuthenticationService
    {
        $authenticationService = $this->createMock(AuthenticationService::class);
        $authenticationService->expects($this->any())
            ->method('getAccessToken')
            ->willReturn($this::TOKEN);

        return $authenticationService;
    }

    /**
     * Mock metrics service.
     *
     * @return MetricsService
     *   Mocked Metrics Service
     */
    private function getMetricsService(): MetricsService
    {
        $metricsService = $this->createMock(MetricsService::class);
        $metricsService->expects($this->any())
            ->method('counter');

        return $metricsService;
    }
}
