<?php
/**
 * @file
 * OverDrive API client
 *
 * @see https://developer.overdrive.com/discovery-apis
 */

namespace App\Service\VendorService\OverDrive\Api;

use App\Service\VendorService\OverDrive\Api\Exception\AccountException;
use App\Service\VendorService\OverDrive\Api\Exception\AuthException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Class Client.
 */
class Client
{
    private const MAX_RETRIES = 5;

    private const OAUTH_URL_BASE = 'https://oauth.overdrive.com';
    private const USER_AGENT = 'cover.dandigbib.org';

    /** @var string */
    private $libraryAccountEndpoint;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var AdapterInterface */
    private $cache;

    /** @var ClientInterface */
    private $httpClient;

    /** @var AccessTokenInterface */
    private $accessToken;

    /** @var string */
    private $productsEndpoint;

    /**
     * Client constructor.
     *
     * @param AdapterInterface $cache
     *   Cache adapter for using the application cache
     * @param ClientInterface $httpClient
     *   Http client for api calls
     */
    public function __construct(AdapterInterface $cache, ClientInterface $httpClient)
    {
        $this->cache = $cache;
        $this->httpClient = $httpClient;
    }

    /**
     * Set OverDrive Library account endpoint credentials.
     *
     * The account endpoint is custom to the account and contains
     * the library account id.
     *
     * @see https://developer.overdrive.com/apis/library-account
     *
     * @param string $libraryAccountEndpoint
     *   The custom endpoint for the account used
     */
    public function setLibraryAccountEndpoint(string $libraryAccountEndpoint): void
    {
        $this->libraryAccountEndpoint = $libraryAccountEndpoint;
    }

    /**
     * Set OverDrive authentication credentials.
     *
     * @see https://developer.overdrive.com/apis/client-auth
     *
     * @param string $clientId
     *   Client id
     * @param string $clientSecret
     *   Client secret
     */
    public function setCredentials(string $clientId, string $clientSecret): void
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Get cover url from OverDrive crossRefId.
     *
     * @param string $crossRefId
     *   The OverDrive 'crossRefId'
     *
     * @return string|null
     *   The cover url or null
     *
     * @throws GuzzleException
     * @throws AuthException
     * @throws InvalidArgumentException
     */
    public function getCoverUrl(string $crossRefId): ?string
    {
        $requests = 0;

        // The OverDrive api fails at random but still gives a 200 response.
        // Only indication of failure is that the 'products' key is missing
        // from the response body.
        // If the products key is missing we retry the request 'MAX_RETRIES' times
        do {
            try {
                $response = $this->httpClient->request('GET', $this->getProductsEndpoint(), [
                    'headers' => $this->getHeaders(),
                    'query' => [
                        'crossRefId' => $crossRefId,
                    ],
                ]);

                $content = $response->getBody()->getContents();
                $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

                // Check for the product and images keys.
                $product = isset($json->products) && is_array($json->products) ? array_shift($json->products) : null;
                $images = $product->images ?? null;
            } catch (GuzzleException|\JsonException $exception) {
                // Ignore
                $images = null;
            }

            ++$requests;
        } while (!$images && $requests < self::MAX_RETRIES);

        return $images ? $images->cover->href : null;
    }

    /**
     * Get products from the overdrive api.
     *
     * @param int $limit
     *   The number of products to fetch
     * @param int $offset
     *   The offset to fetch from
     *
     * @return array
     *   Array of 'products' serialized as stdClass
     *
     * @throws AuthException
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getProducts(int $limit, int $offset): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->getProductsEndpoint(), [
                'headers' => $this->getHeaders(),
                'query' => ['limit' => $limit, 'offset' => $offset],
            ]);

            $content = $response->getBody()->getContents();
            $content = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException|\JsonException $exception) {
            // Ignore
        }

        return $content->products ?? [];
    }

    /**
     * Get the total number of products from the overdrive api ('totalItems' field in the response).
     *
     * @return int
     *   The total number of products
     *
     * @throws AuthException
     * @throws InvalidArgumentException
     * @throws IdentityProviderException
     */
    public function getTotalProducts(): int
    {
        try {
            $response = $this->httpClient->request('GET', $this->getProductsEndpoint(), [
                'headers' => $this->getHeaders(),
            ]);

            $content = $response->getBody()->getContents();
            $content = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException|\JsonException $exception) {
            // Ignore
        }

        return $content->totalItems ?? 0;
    }

    /**
     * Get the headers for OverDrive api calls.
     *
     * @return string[] Array of headers
     *
     * @throws AuthException
     * @throws GuzzleException
     * @throws IdentityProviderException
     * @throws InvalidArgumentException
     *
     * @psalm-return array{User-Agent: 'cover.dandigbib.org', Content-Type: 'application/json', Authorization: string}
     */
    private function getHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->getAccessToken()->getToken(),
        ];
    }

    /**
     * Get the products endpoint for the account used.
     *
     * @return string
     *   The complete URI for the products endpoint
     *
     * @throws AccountException
     * @throws AuthException
     * @throws GuzzleException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function getProductsEndpoint(): string
    {
        if (empty($this->productsEndpoint)) {
            $this->productsEndpoint = $this->fetchProductsEndpoint();
        }

        return $this->productsEndpoint;
    }

    /**
     * Fetch the products endpoint for the account used from the Library API.
     *
     * @see https://developer.overdrive.com/apis/library-account
     *
     * @return string
     *   The complete URI for the products endpoint
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     * @throws AuthException
     * @throws AccountException
     * @throws \JsonException
     * @throws IdentityProviderException
     */
    private function fetchProductsEndpoint(): string
    {
        $item = $this->cache->getItem('overdrive.api.productsEndpoint');

        if ($item->isHit()) {
            $endpoint = $item->get();
        } else {
            if (!$this->libraryAccountEndpoint) {
                throw new AccountException('Credentials missing. Please set libraryAccountEndpoint, ClientId and ClientSecret');
            }

            $response = $this->httpClient->request('GET', $this->libraryAccountEndpoint, [
                    'headers' => $this->getHeaders(),
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

            // Store products endpoint in local cache.
            $item->set($json->links->products->href);
            $this->cache->save($item);

            $endpoint = $json->links->products->href;
        }

        return $endpoint;
    }

    /**
     * Authenticate against OverDrive.
     *
     * @see https://developer.overdrive.com/apis/client-auth
     *
     * @return AccessTokenInterface
     *   The access token
     *
     * @throws AuthException
     * @throws IdentityProviderException
     * @throws InvalidArgumentException
     */
    private function authenticate(): AccessTokenInterface
    {
        $item = $this->cache->getItem('overdrive.api.access_token');

        if ($item->isHit()) {
            $accessToken = $item->get();
        } else {
            $accessToken = $this->getAuthProvider()->getAccessToken('client_credentials');

            // Store authorization in local cache.
            $item->expiresAfter($accessToken->getExpires() - time());
            $item->set($accessToken);
            $this->cache->save($item);
        }

        // Get refresh token if needed
        if ($accessToken->hasExpired()) {
            $accessToken = $this->getAuthProvider()->getAccessToken('refresh_token', [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);

            $item->expiresAfter($accessToken->getExpires() - time());
            $item->set($accessToken);
            $this->cache->save($item);
        }

        return $accessToken;
    }

    /**
     * Get OverDrive authorization.
     *
     * If not in local cache an request to OverDrive for a new authorization will
     * be executed.
     *
     * @return AccessTokenInterface
     *   The value for the authentication header
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     * @throws AuthException
     * @throws IdentityProviderException
     */
    private function getAccessToken(): AccessTokenInterface
    {
        if (empty($this->accessToken) || $this->accessToken->hasExpired()) {
            $this->accessToken = $this->authenticate();
        }

        return $this->accessToken;
    }

    /**
     * Get OverDrive OAuth authentication provider.
     *
     * @return GenericProvider
     *   The authentication provider
     *
     * @throws AuthException
     */
    private function getAuthProvider(): GenericProvider
    {
        if (!$this->libraryAccountEndpoint || !$this->clientId || !$this->clientSecret) {
            throw new AuthException('Credentials missing. Please set ClientId and ClientSecret');
        }

        return new GenericProvider([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'urlAuthorize' => self::OAUTH_URL_BASE.'/authorize',
            'urlAccessToken' => self::OAUTH_URL_BASE.'/token',
            'urlResourceOwnerDetails' => self::OAUTH_URL_BASE.'/resource',
        ]);
    }
}
