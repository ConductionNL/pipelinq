<?php

/**
 * Unit tests for KvkApiClient.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\KvkResultMapper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KvkApiClient.
 */
class KvkApiClientTest extends TestCase
{
    /**
     * Test search returns empty for empty API key.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForEmptyApiKey(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new KvkResultMapper();

        $client = new KvkApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search('', ['sbiCodes' => ['62']]));
    }//end testSearchReturnsEmptyForEmptyApiKey()

    /**
     * Test search returns empty for empty SBI codes.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForNoSbiCodes(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new KvkResultMapper();

        $client = new KvkApiClient($clientService, $logger, $resultMapper);

        $this->assertSame([], $client->search('api-key', ['sbiCodes' => []]));
    }//end testSearchReturnsEmptyForNoSbiCodes()

    /**
     * The SBI code must be forwarded as `sbiHoofdActiviteit` in the constructed URL.
     *
     * This covers C-W7-02: previously fetchResults() ignored the $sbiCode parameter,
     * making ICP/SBI filtering completely inert.
     *
     * @return void
     */
    public function testSearchForwardsSbiCodeInRequestUrl(): void
    {
        $capturedUrl = null;

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"resultaten": []}');

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')
            ->willReturnCallback(
                static function (string $uri, array $options=[]) use (&$capturedUrl, $response): IResponse {
                    $capturedUrl = $uri;
                    return $response;
                }
            );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $logger       = $this->createMock(LoggerInterface::class);
        $resultMapper = new KvkResultMapper();

        $client = new KvkApiClient($clientService, $logger, $resultMapper);
        $client->search('test-api-key', ['sbiCodes' => ['6201']]);

        $this->assertNotNull($capturedUrl, 'HTTP request was never made');
        $this->assertStringContainsString(
            'sbiHoofdActiviteit=6201',
            (string) $capturedUrl,
            'SBI code must be forwarded as sbiHoofdActiviteit query parameter'
        );
    }//end testSearchForwardsSbiCodeInRequestUrl()
}//end class
