<?php

/**
 * @file
 * Test cases for the Open Platform authentication service.
 */

namespace Tests\Service\OpenPlatform;

use App\Exception\OpenPlatformAuthException;
use App\Service\OpenPlatform\AuthenticationService;
use JsonException;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuthenticationServiceTest extends TestCase
{
    public const TOKEN = 'fde1432d66d33e4cq66e5ad04757811e47864329';

    /**
     * Test that token is returned.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws OpenPlatformAuthException
     */
    public function testGetAccessToken()
    {
        $body = json_encode([
            'token_type' => 'bearer',
            'access_token' => $this::TOKEN,
            'expires_in' => 2592000,
        ]);

        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);

        $service = $this->getAuthenticationService(false, $client);

        $this->assertEquals($this::TOKEN, $service->getAccessToken());
    }

    /**
     * Test that a token is return if cache is enabled.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws OpenPlatformAuthException
     */
    public function testGetAccessTokenCache()
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 200]),
        ]);
        $service = $this->getAuthenticationService(true, $client);

        $this->assertEquals($this::TOKEN, $service->getAccessToken());
    }

    /**
     * Test that PlatformAuthException is throw on client error.
     *
     * @throws OpenPlatformAuthException|InvalidArgumentException|JsonException
     */
    public function testErrorHandling()
    {
        $this->expectException(OpenPlatformAuthException::class);

        $client = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 500]),
        ]);
        $service = $this->getAuthenticationService(false, $client);
        $service->getAccessToken();
    }

    /**
     * Build service with mocked injections.
     *
     * @param bool $cacheHit
     * @param HttpClientInterface $client
     *
     * @return AuthenticationService
     */
    private function getAuthenticationService(bool $cacheHit, HttpClientInterface $client): AuthenticationService
    {
        // Setup basic cache.
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->any())
            ->method('get')
            ->willReturn($this::TOKEN);
        $cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn($cacheHit);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($cacheItem);

        $logger = $this->createMock(LoggerInterface::class);

        return new AuthenticationService($cache, $logger, $client, '', '', '', '');
    }
}
