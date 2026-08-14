<?php

/**
 * Unit tests for ZtcClient.
 *
 * Covers REQ-ZGW-005: zaaktype URL resolved from omschrijving, cache hit
 * suppresses ZTC traffic, and NRC catalogi notifications (via
 * `invalidateCache()`) clear the cache.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZaaktypeNotInCatalogusException;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZtcClient.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-005
 */
class ZtcClientTest extends TestCase {
	/**
	 * Endpoint payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $endpoint;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->endpoint = [
			'id' => 'zgw-ep-zoetermeer-openzaak',
			'componenten' => ['ztc' => 'https://open-zaak.zoetermeer.nl/catalogi/api/v1'],
		];
	}//end setUp()

	/**
	 * Helper to build a ZtcClient with a controllable transport.
	 *
	 * @param array<int, array<string, mixed>> $responses Sequential callComponent responses.
	 *
	 * @return array{0:ZtcClient, 1:int}
	 */
	private function build(array $responses): array {
		$api = $this->createMock(ZgwApiClient::class);
		$calls = 0;
		$api->method('callComponent')->willReturnCallback(
			function () use ($responses, &$calls): array {
				$resp = $responses[$calls] ?? null;
				$calls++;
				if ($resp === null) {
					throw new \RuntimeException('unexpected extra callComponent invocation');
				}
				return $resp;
			}
		);

		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('findClientForEndpoint')->willReturn([
			'clientIdentifier' => 'pipelinq-zoetermeer',
			'secretKluisRef' => 'vault://x',
			'userId' => 'pipelinq',
			'userRepresentation' => 'Pipelinq',
		]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$client = new ZtcClient($api, $registers, $appConfig, $this->createMock(LoggerInterface::class));
		return [$client, &$calls];
	}//end build()

	/**
	 * Zaaktype resolved from omschrijving and cached.
	 *
	 * @return void
	 */
	public function testResolveZaaktypeCachesUrl(): void {
		$expectedUrl = 'https://open-zaak.zoetermeer.nl/catalogi/api/v1/zaaktypen/aa11-evenementen';
		[$client, $calls] = $this->build([
			['status' => 200, 'headers' => [], 'body' => ['results' => [['url' => $expectedUrl, 'omschrijving' => 'Evenementenvergunning']]]],
		]);

		$url1 = $client->resolveZaaktype($this->endpoint, 'Evenementenvergunning');
		self::assertSame($expectedUrl, $url1);

		// Cache hit on the second call → no second callComponent invocation.
		$url2 = $client->resolveZaaktype($this->endpoint, 'Evenementenvergunning');
		self::assertSame($expectedUrl, $url2);
	}//end testResolveZaaktypeCachesUrl()

	/**
	 * Empty results → ZaaktypeNotInCatalogusException.
	 *
	 * @return void
	 */
	public function testEmptyResultsRaisesNotInCatalogus(): void {
		[$client] = $this->build([
			['status' => 200, 'headers' => [], 'body' => ['results' => []]],
		]);
		$this->expectException(ZaaktypeNotInCatalogusException::class);
		$client->resolveZaaktype($this->endpoint, 'NietBestaand');
	}//end testEmptyResultsRaisesNotInCatalogus()

	/**
	 * invalidateCache forces a re-fetch on next resolve.
	 *
	 * @return void
	 */
	public function testInvalidateCacheClearsBucket(): void {
		$expected1 = 'https://open-zaak.zoetermeer.nl/catalogi/api/v1/zaaktypen/aa11-v1';
		$expected2 = 'https://open-zaak.zoetermeer.nl/catalogi/api/v1/zaaktypen/aa11-v2';

		[$client] = $this->build([
			['status' => 200, 'headers' => [], 'body' => ['results' => [['url' => $expected1, 'omschrijving' => 'Evenementenvergunning']]]],
			['status' => 200, 'headers' => [], 'body' => ['results' => [['url' => $expected2, 'omschrijving' => 'Evenementenvergunning']]]],
		]);
		self::assertSame($expected1, $client->resolveZaaktype($this->endpoint, 'Evenementenvergunning'));
		$client->invalidateCache($this->endpoint, ZtcClient::RESOURCE_ZAAKTYPE);
		self::assertSame($expected2, $client->resolveZaaktype($this->endpoint, 'Evenementenvergunning'));
	}//end testInvalidateCacheClearsBucket()

}//end class
