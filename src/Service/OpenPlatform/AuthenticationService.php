<?php

/**
 * @file
 * Service that handle authentication against the Open Platform.
 *
 * Uses oAuth2 request to get access token and stores in cache until expire to
 * speed up the process and make as few calls as possible.
 */

namespace App\Service\OpenPlatform;

use App\Exception\OpenPlatformAuthException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class AuthenticationService.
 */
class AuthenticationService
{
    // Used to give the token some grace-period, so it will not expire will
    // being used. Currently, the token is valid for 30 days. So we set the
    // limit to be 1 day, so it will be refreshed before it expires.
    final public const TOKEN_EXPIRE_LIMIT = 86400;
    private string $accessToken = '';

    /**
     * Authentication constructor.
     *
     * @param ParameterBagInterface $params
     *   Used to get parameters form the environment
     * @param CacheItemPoolInterface $cache
     *   Cache to store access token
     * @param LoggerInterface $logger
     *   Logger object to send stats to ES
     * @param HttpClientInterface $httpClient
     *   Http Client
     */
    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * Get access token.
     *
     * If not in local cache an request to the open platform for a new token will
     * be executed.
     *
     * @param bool $refresh
     *   If TRUE refresh token. Default: FALSE.
     *
     *   The access token
     *
     * @throws OpenPlatformAuthException
     * @throws \JsonException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getAccessToken(bool $refresh = false): string
    {
        if (empty($this->accessToken)) {
            $this->accessToken = $this->authenticate($refresh);
        }

        return $this->accessToken;
    }

    /**
     * Authenticate against open platform.
     *
     * @param bool $refresh
     *   If TRUE refresh token. Default: FALSE.
     *
     *   The token if successful else the empty string,
     *
     * @throws OpenPlatformAuthException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \JsonException
     */
    private function authenticate(bool $refresh = false): string
    {
        // Try getting item from cache.
        $item = $this->cache->getItem('openplatform.access_token');

        // Check if the access token is located in local file cache to speed up the
        // process.
        if ($item->isHit() && !$refresh) {
            $this->logger->info('Access token requested', [
                'service' => 'AuthenticationService',
                'cache' => true,
            ]);

            return $item->get();
        } else {
            try {
                $response = $this->httpClient->request('POST', $this->params->get('openPlatform.auth.url'), [
                    'body' => [
                        'grant_type' => 'password',
                        'username' => '@'.$this->params->get('openPlatform.auth.agency'),
                        'password' => '@'.$this->params->get('openPlatform.auth.agency'),
                    ],
                    'auth_basic' => [
                        $this->params->get('openPlatform.auth.id'),
                        $this->params->get('openPlatform.auth.secret'),
                    ],
                ]);
            } catch (TransportExceptionInterface $exception) {
                $this->logger->error('Access token not acquired', [
                    'service' => 'AuthenticationService',
                    'cache' => false,
                    'message' => $exception->getMessage(),
                ]);

                throw new OpenPlatformAuthException($exception->getMessage(), (int) $exception->getCode(), $exception);
            } catch (\Exception $exception) {
                $this->logger->error('Unknown error in acquiring access token', [
                    'service' => 'AuthenticationService',
                    'message' => $exception->getMessage(),
                ]);

                throw new OpenPlatformAuthException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }

            if (200 !== $response->getStatusCode()) {
                throw new OpenPlatformAuthException('Authentication service returned non 200 status code', (int) $response->getStatusCode());
            }

            // Get the content and parse json object as an array.
            $content = $response->getContent();
            $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $this->logger->info('Access token acquired', [
                'service' => 'AuthenticationService',
                'cache' => false,
            ]);

            // Store access token in local cache.
            $item->expiresAfter($json['expires_in'] - $this::TOKEN_EXPIRE_LIMIT);
            $item->set($json['access_token']);
            $this->cache->save($item);

            return $json['access_token'];
        }
    }
}
