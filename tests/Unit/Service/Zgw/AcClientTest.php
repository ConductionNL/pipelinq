<?php

/**
 * Unit tests for AcClient.
 *
 * Covers REQ-ZGW-006 scope enforcement:
 *   - hasScope() returns true when the cache contains the required scope.
 *   - hasScope() returns false on stale-beyond-2x-window caches.
 *   - require() raises InsufficientScopeException on miss.
 *
 * Refresh path is exercised by injecting a primed cache (the live AC HTTP
 * fetch belongs in an integration test).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\AcClient;
use OCA\Pipelinq\Service\Zgw\InsufficientScopeException;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AcClient scope cache + pre-flight guards.
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-006
 */
class AcClientTest extends TestCase {
	/**
	 * Build an AcClient with a no-op transport and a mock registry.
	 *
	 * @return AcClient
	 */
	private function makeClient(): AcClient {
		$api = $this->createMock(ZgwApiClient::class);
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$registers->method('findClientForEndpoint')->willReturn(null);
		return new AcClient($api, $registers, $appConfig, $logger);
	}//end makeClient()

	/**
	 * Test: primed cache satisfies hasScope().
	 *
	 * @return void
	 */
	public function testPrimedCacheGrantsScope(): void {
		$client = $this->makeClient();
		$endpoint = ['id' => 'zgw-ep-zoetermeer-openzaak', 'componenten' => ['ac' => 'https://ac.example']];
		$client->primeCache(
			'zgw-ep-zoetermeer-openzaak',
			['https://zk/zaaktype/1' => ['zaken.aanmaken', 'zaken.lezen']]
		);

		self::assertTrue($client->hasScope($endpoint, 'https://zk/zaaktype/1', 'zaken.aanmaken'));
		self::assertTrue($client->hasScope($endpoint, 'https://zk/zaaktype/1', 'zaken.lezen'));
		self::assertFalse($client->hasScope($endpoint, 'https://zk/zaaktype/1', 'zaken.bijwerken'));
	}//end testPrimedCacheGrantsScope()

	/**
	 * Test: require() raises on missing scope (REQ-ZGW-006 missing-scope scenario).
	 *
	 * @return void
	 */
	public function testRequireRaisesOnMissingScope(): void {
		$client = $this->makeClient();
		$endpoint = ['id' => 'zgw-ep-zoetermeer-openzaak'];
		$client->primeCache('zgw-ep-zoetermeer-openzaak', ['https://zk/zaaktype/1' => ['zaken.lezen']]);

		$this->expectException(InsufficientScopeException::class);
		$client->require($endpoint, 'https://zk/zaaktype/1', 'zaken.aanmaken');
	}//end testRequireRaisesOnMissingScope()

	/**
	 * Test: stale-beyond-2x-window cache fails closed.
	 *
	 * @return void
	 */
	public function testCacheStaleBeyondTwoWindowsFailsClosed(): void {
		$client = $this->makeClient();
		$endpoint = ['id' => 'ep-stale'];
		// Default refresh interval is 900s; prime with timestamp 3000s ago (> 2 * 900).
		$client->primeCache(
			'ep-stale',
			['https://zk/zaaktype/2' => ['zaken.aanmaken']],
			time() - 3000
		);
		self::assertFalse($client->hasScope($endpoint, 'https://zk/zaaktype/2', 'zaken.aanmaken'));
	}//end testCacheStaleBeyondTwoWindowsFailsClosed()

	/**
	 * Test: wildcard scope (granted on '*') matches any resource.
	 *
	 * @return void
	 */
	public function testWildcardScopeMatchesAnyResource(): void {
		$client = $this->makeClient();
		$endpoint = ['id' => 'ep-component-level'];
		$client->primeCache('ep-component-level', ['*' => ['documenten.aanmaken']]);
		self::assertTrue($client->hasScope($endpoint, 'https://drc/io/anything', 'documenten.aanmaken'));
	}//end testWildcardScopeMatchesAnyResource()

	/**
	 * Test: getScopesFor merges resource-specific + wildcard buckets.
	 *
	 * @return void
	 */
	public function testGetScopesForMergesWildcardAndResource(): void {
		$client = $this->makeClient();
		$endpoint = ['id' => 'ep-merged'];
		$client->primeCache('ep-merged', [
			'https://zk/zaaktype/3' => ['zaken.aanmaken'],
			'*' => ['catalogi.lezen'],
		]);
		$merged = $client->getScopesFor($endpoint, 'https://zk/zaaktype/3');
		sort($merged);
		self::assertSame(['catalogi.lezen', 'zaken.aanmaken'], $merged);
	}//end testGetScopesForMergesWildcardAndResource()

}//end class
