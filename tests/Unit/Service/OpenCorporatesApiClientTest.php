<?php

/**
 * Unit tests for OpenCorporatesApiClient.
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

use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\OpenCorporatesResultMapper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OpenCorporatesApiClient.
 */
class OpenCorporatesApiClientTest extends TestCase
{
    /**
     * Build an IAppConfig mock that echoes the supplied default string value.
     *
     * @return IAppConfig The configured mock.
     */
    private function appConfigMock(): IAppConfig
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => $default
        );

        return $appConfig;
    }//end appConfigMock()

    /**
     * An IURLGenerator mock that echoes the path as the absolute URL.
     *
     * @return IURLGenerator The configured mock.
     */
    private function urlGen(): IURLGenerator
    {
        $urlGen = $this->createMock(IURLGenerator::class);
        $urlGen->method('getAbsoluteURL')->willReturnCallback(
            static fn(string $path): string => 'http://localhost'.$path
        );
        return $urlGen;
    }//end urlGen()

    /**
     * Test search returns empty for empty keywords.
     *
     * @return void
     */
    public function testSearchReturnsEmptyForNoKeywords(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new OpenCorporatesResultMapper();

        $client = new OpenCorporatesApiClient($clientService, $this->appConfigMock(), $logger, $resultMapper, $this->urlGen());

        $this->assertSame([], $client->search(['keywords' => []]));
    }//end testSearchReturnsEmptyForNoKeywords()

    /**
     * Test search returns empty with no keywords key.
     *
     * @return void
     */
    public function testSearchReturnsEmptyWithoutKeywordsKey(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $resultMapper  = new OpenCorporatesResultMapper();

        $client = new OpenCorporatesApiClient($clientService, $this->appConfigMock(), $logger, $resultMapper, $this->urlGen());

        $this->assertSame([], $client->search([]));
    }//end testSearchReturnsEmptyWithoutKeywordsKey()

    /**
     * OR-first: when the OpenRegister OpenCorporates leaf returns 200 with raw
     * company objects, the client maps them through OpenCorporatesResultMapper
     * (identical to the legacy path) and never touches the direct endpoint.
     *
     * @return void
     */
    public function testSearchUsesOpenRegisterLeafWhenAvailable(): void
    {
        $orUrl     = null;
        $legacyHit = false;

        $company  = ['company_number' => '12345678', 'name' => 'Acme BV', 'jurisdiction_code' => 'nl'];
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['results' => [$company], 'total' => 1]));

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willReturnCallback(
            static function (string $uri, array $options=[]) use (&$orUrl, &$legacyHit, $response): IResponse {
                if (str_contains($uri, 'integrations/opencorporates') === true) {
                    $orUrl = $uri;
                    return $response;
                }

                $legacyHit = true;
                throw new \RuntimeException('legacy path must not be hit when OR is available');
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $client = new OpenCorporatesApiClient(
            $clientService,
            $this->appConfigMock(),
            $this->createMock(LoggerInterface::class),
            new OpenCorporatesResultMapper(),
            $this->urlGen()
        );

        $results = $client->search(['keywords' => ['acme']]);

        $this->assertFalse($legacyHit, 'legacy OpenCorporates endpoint must not be called when OR returns 200');
        $this->assertNotNull($orUrl, 'OR leaf endpoint was not called');
        $this->assertStringContainsString('integrations/opencorporates/search', (string) $orUrl);
        $this->assertCount(1, $results);
        $this->assertSame('12345678', $results[0]['kvkNumber']);
        $this->assertSame('Acme BV', $results[0]['tradeName']);
    }//end testSearchUsesOpenRegisterLeafWhenAvailable()

    /**
     * OR-first safe-partial: when the OR leaf is unavailable (503 / absent),
     * the client falls back to the legacy direct path and still maps results.
     *
     * @return void
     */
    public function testSearchFallsBackToLegacyWhenLeafUnavailable(): void
    {
        $legacyUrl = null;

        $company        = ['company_number' => '87654321', 'name' => 'Beta NV'];
        $legacyResponse = $this->createMock(IResponse::class);
        $legacyResponse->method('getBody')->willReturn(
            json_encode(['results' => ['companies' => [['company' => $company]]]])
        );

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willReturnCallback(
            static function (string $uri, array $options=[]) use (&$legacyUrl, $legacyResponse): IResponse {
                if (str_contains($uri, 'integrations/opencorporates') === true) {
                    throw new \RuntimeException('OR source unavailable (503)');
                }

                $legacyUrl = $uri;
                return $legacyResponse;
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $client = new OpenCorporatesApiClient(
            $clientService,
            $this->appConfigMock(),
            $this->createMock(LoggerInterface::class),
            new OpenCorporatesResultMapper(),
            $this->urlGen()
        );

        $results = $client->search(['keywords' => ['beta']]);

        $this->assertNotNull($legacyUrl, 'legacy OpenCorporates endpoint must be called on OR fallback');
        $this->assertStringContainsString('/companies/search', (string) $legacyUrl);
        $this->assertCount(1, $results);
        $this->assertSame('87654321', $results[0]['kvkNumber']);
    }//end testSearchFallsBackToLegacyWhenLeafUnavailable()
}//end class
