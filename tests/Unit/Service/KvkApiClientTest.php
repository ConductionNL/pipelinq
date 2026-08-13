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
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KvkApiClient.
 */
class KvkApiClientTest extends TestCase {
	/**
	 * Build an IAppConfig mock that echoes the supplied default string value.
	 *
	 * @param string|null $apiBaseOverride Optional value to return for kvk.api_base_url.
	 *
	 * @return IAppConfig The configured mock.
	 */
	private function appConfigMock(?string $apiBaseOverride = null): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($apiBaseOverride): string {
				if ($key === 'kvk.api_base_url' && $apiBaseOverride !== null) {
					return $apiBaseOverride;
				}

				return $default;
			}
		);

		return $appConfig;
	}//end appConfigMock()

	/**
	 * An IURLGenerator mock that echoes the path as the absolute URL, so the
	 * OR-leaf URL contains `integrations/kvk` for the get-callback to detect.
	 *
	 * @return IURLGenerator The configured mock.
	 */
	private function urlGen(): IURLGenerator {
		$urlGen = $this->createMock(IURLGenerator::class);
		$urlGen->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'http://localhost' . $path
		);
		return $urlGen;
	}//end urlGen()

	/**
	 * Build a get-callback that forces the OR-first leg to fall back (so the
	 * legacy direct path runs and its URL is captured), routing the legacy
	 * call to the supplied response and recording its URL by reference.
	 *
	 * @param IResponse $response The legacy-path response to return.
	 * @param string|null $capturedUrl Captured legacy URL (by reference).
	 *
	 * @return callable
	 */
	private function legacyOnlyGetCallback(IResponse $response, ?string &$capturedUrl): callable {
		return static function (string $uri, array $options = []) use (&$capturedUrl, $response): IResponse {
			if (str_contains($uri, 'integrations/kvk') === true) {
				// OR leaf not usable in this test — force fallback to legacy.
				throw new \RuntimeException('OR source unavailable (503)');
			}

			$capturedUrl = $uri;
			return $response;
		};
	}//end legacyOnlyGetCallback()

	/**
	 * Test search returns empty for empty API key.
	 *
	 * @return void
	 */
	public function testSearchReturnsEmptyForEmptyApiKey(): void {
		$clientService = $this->createMock(IClientService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$resultMapper = new KvkResultMapper();

		$client = new KvkApiClient($clientService, $this->appConfigMock(), $logger, $resultMapper, $this->urlGen());

		$this->assertSame([], $client->search('', ['sbiCodes' => ['62']]));
	}//end testSearchReturnsEmptyForEmptyApiKey()

	/**
	 * Test search returns empty for empty SBI codes.
	 *
	 * @return void
	 */
	public function testSearchReturnsEmptyForNoSbiCodes(): void {
		$clientService = $this->createMock(IClientService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$resultMapper = new KvkResultMapper();

		$client = new KvkApiClient($clientService, $this->appConfigMock(), $logger, $resultMapper, $this->urlGen());

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
	public function testSearchForwardsSbiCodeInRequestUrl(): void {
		$capturedUrl = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"resultaten": []}');

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')
			->willReturnCallback($this->legacyOnlyGetCallback($response, $capturedUrl));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($httpClient);

		$logger = $this->createMock(LoggerInterface::class);
		$resultMapper = new KvkResultMapper();

		$client = new KvkApiClient($clientService, $this->appConfigMock(), $logger, $resultMapper, $this->urlGen());
		$client->search('test-api-key', ['sbiCodes' => ['6201']]);

		$this->assertNotNull($capturedUrl, 'HTTP request was never made');
		$this->assertStringContainsString(
			'sbiHoofdActiviteit=6201',
			(string)$capturedUrl,
			'SBI code must be forwarded as sbiHoofdActiviteit query parameter'
		);
		$this->assertStringContainsString(
			'https://api.kvk.nl/api/v1/zoeken',
			(string)$capturedUrl,
			'Default KVK base URL must be used when unconfigured (behavior-preserving)'
		);
	}//end testSearchForwardsSbiCodeInRequestUrl()

	/**
	 * An admin-configured KVK base URL must replace the default host in the request URL.
	 *
	 * @return void
	 */
	public function testSearchUsesConfiguredApiBaseUrl(): void {
		$capturedUrl = null;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"resultaten": []}');

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')
			->willReturnCallback($this->legacyOnlyGetCallback($response, $capturedUrl));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($httpClient);

		$logger = $this->createMock(LoggerInterface::class);
		$resultMapper = new KvkResultMapper();

		$client = new KvkApiClient(
			$clientService,
			$this->appConfigMock(apiBaseOverride: 'https://api.kvk.example/api/v2/'),
			$logger,
			$resultMapper,
			$this->urlGen()
		);
		$client->search('test-api-key', ['sbiCodes' => ['6201']]);

		$this->assertNotNull($capturedUrl, 'HTTP request was never made');
		$this->assertStringContainsString(
			'https://api.kvk.example/api/v2/zoeken',
			(string)$capturedUrl,
			'Configured base URL must be used and trailing slash normalised'
		);
		$this->assertStringNotContainsString(
			'api.kvk.nl',
			(string)$capturedUrl,
			'Default host must not leak when an override is configured'
		);
	}//end testSearchUsesConfiguredApiBaseUrl()

	/**
	 * OR-first: when the OpenRegister KvK leaf returns 200 with raw rows, the
	 * client maps them through KvkResultMapper (identical to the legacy path)
	 * and never touches the direct KvK endpoint.
	 *
	 * @return void
	 */
	public function testSearchUsesOpenRegisterLeafWhenAvailable(): void {
		$orUrl = null;
		$legacyHit = false;

		$rawRow = [
			'kvkNummer' => '12345678',
			'eersteHandelsnaam' => 'Acme BV',
			'type' => 'hoofdvestiging',
		];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['results' => [$rawRow], 'total' => 1]));

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturnCallback(
			static function (string $uri, array $options = []) use (&$orUrl, &$legacyHit, $response): IResponse {
				if (str_contains($uri, 'integrations/kvk') === true) {
					$orUrl = $uri;
					return $response;
				}

				$legacyHit = true;
				throw new \RuntimeException('legacy path must not be hit when OR is available');
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($httpClient);

		$client = new KvkApiClient(
			$clientService,
			$this->appConfigMock(),
			$this->createMock(LoggerInterface::class),
			new KvkResultMapper(),
			$this->urlGen()
		);

		$results = $client->search('test-api-key', ['sbiCodes' => ['6201']]);

		$this->assertFalse($legacyHit, 'legacy KvK endpoint must not be called when OR returns 200');
		$this->assertNotNull($orUrl, 'OR leaf endpoint was not called');
		$this->assertStringContainsString('integrations/kvk/search', (string)$orUrl);
		$this->assertStringContainsString('sbiHoofdActiviteit=6201', (string)$orUrl);
		$this->assertCount(1, $results);
		$this->assertSame('12345678', $results[0]['kvkNumber']);
		$this->assertSame('Acme BV', $results[0]['tradeName']);
	}//end testSearchUsesOpenRegisterLeafWhenAvailable()

	/**
	 * OR-first safe-partial: when the OR leaf is unavailable (503 / absent),
	 * the client falls back to the legacy direct path and still maps results,
	 * so configured envs keep working.
	 *
	 * @return void
	 */
	public function testSearchFallsBackToLegacyWhenLeafUnavailable(): void {
		$legacyUrl = null;

		$rawRow = ['kvkNummer' => '87654321', 'name' => 'Beta NV'];
		$legacyResponse = $this->createMock(IResponse::class);
		$legacyResponse->method('getBody')->willReturn(json_encode(['resultaten' => [$rawRow]]));

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturnCallback(
			static function (string $uri, array $options = []) use (&$legacyUrl, $legacyResponse): IResponse {
				if (str_contains($uri, 'integrations/kvk') === true) {
					throw new \RuntimeException('OR source unavailable (503)');
				}

				$legacyUrl = $uri;
				return $legacyResponse;
			}
		);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($httpClient);

		$client = new KvkApiClient(
			$clientService,
			$this->appConfigMock(),
			$this->createMock(LoggerInterface::class),
			new KvkResultMapper(),
			$this->urlGen()
		);

		$results = $client->search('test-api-key', ['sbiCodes' => ['6201']]);

		$this->assertNotNull($legacyUrl, 'legacy KvK endpoint must be called on OR fallback');
		$this->assertStringContainsString('/zoeken', (string)$legacyUrl);
		$this->assertCount(1, $results);
		$this->assertSame('87654321', $results[0]['kvkNumber']);
	}//end testSearchFallsBackToLegacyWhenLeafUnavailable()
}//end class
