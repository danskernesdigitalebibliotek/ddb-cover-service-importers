<?php

namespace App\Tests\Service\DataWell;

use App\Service\DataWell\DataWellClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DataWellClientTest extends TestCase
{
    public function testSearch(): void
    {
        $responseBody = \file_get_contents(__DIR__.'/Fixtures/testSearch.json');

        $mockHttpClient = new MockHttpClient([
            new MockResponse($responseBody, [
                'http_code' => 200,
            ]),
        ]);

        $client = $this->getDataWellClient($mockHttpClient);

        [$jsonContent, $more, $offset] = $client->search('query', 0);

        $this->assertIsArray($jsonContent);
        $this->assertArrayHasKey('searchResponse', $jsonContent);
        $this->assertArrayHasKey('result', $jsonContent['searchResponse']);
        $this->assertArrayHasKey('searchResult', $jsonContent['searchResponse']['result']);
        $this->assertCount(50, $jsonContent['searchResponse']['result']['searchResult']);
        $this->assertTrue($more);
        $this->assertEquals(50, $offset);
    }

    public function testExtractCoverUrl(): void
    {
        $responseBody = \file_get_contents(__DIR__.'/Fixtures/testSearch.json');

        $mockHttpClient = new MockHttpClient([
            new MockResponse($responseBody, [
                'http_code' => 200,
            ]),
        ]);

        $client = $this->getDataWellClient($mockHttpClient);

        [$jsonContent, $more, $offset] = $client->search('query', 0);

        $pidArray = $client->extractCoverUrl($jsonContent, 'dbcaddi:hasCover');

        $this->assertCount(50, $pidArray);
        $this->assertArrayHasKey('150070-comics:IV69838DaNe', $pidArray);
        $this->assertEquals(
            'https://cdpmm-public.s3.amazonaws.com/store/cover/padmedium/154114D13FD.png',
            $pidArray['150070-comics:IV69838DaNe']
        );
        $this->assertTrue($more);
        $this->assertEquals(50, $offset);
    }

    public function testExtractData(): void
    {
        $responseBody = \file_get_contents(__DIR__.'/Fixtures/testSearch.json');

        $mockHttpClient = new MockHttpClient([
            new MockResponse($responseBody, [
                'http_code' => 200,
            ]),
        ]);

        $client = $this->getDataWellClient($mockHttpClient);

        [$jsonContent, $more, $offset] = $client->search('query', 0);

        $pidArray = $client->extractData($jsonContent);

        $this->assertCount(50, $pidArray);
        $this->assertArrayHasKey('150070-comics:IV69838DaNe', $pidArray);
        $this->assertIsArray($pidArray['150070-comics:IV69838DaNe']);
        $this->assertIsArray($pidArray['150070-comics:IV69838DaNe']['identifier']);
        $this->assertEquals('150070-comics:IV69838DaNe', $pidArray['150070-comics:IV69838DaNe']['identifier']['$']);
        $this->assertTrue($more);
        $this->assertEquals(50, $offset);
    }

    public function testHasMoreResults(): void
    {
        $responseBody = '{
            "searchResponse": {
                "result": {
                    "more": {
                        "$": "false"
                    }
                }
            }
        }';

        $mockHttpClient = new MockHttpClient([
            new MockResponse($responseBody, [
                'http_code' => 200,
            ]),
        ]);

        $client = $this->getDataWellClient($mockHttpClient);

        [$jsonContent, $more, $offset] = $client->search('query', 0);

        $this->assertFalse($more);
    }

    private function getDataWellClient(HttpClientInterface $mockHttpClient): DataWellClient
    {
        return new DataWellClient(
            'agency',
            'profile',
            'searchURL',
            'password',
            'user',
            $mockHttpClient,
        );
    }
}
