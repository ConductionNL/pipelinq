<?php

/**
 * Unit tests for AcClient's scope lookups outside a primed cache.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\AcClient;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AcClient: guards on the endpoint and scope, the lazy refresh on a cold
 * cache, the fallback refresh interval and require() on a granted scope.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\AcClient
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-006
 */
class AcClientScopeLookupTest extends TestCase {

	/**
	 * Transport mock.
	 *
	 * @var ZgwApiClient&MockObject
	 */
	private ZgwApiClient $api;

	/**
	 * Register facade mock.
	 *
	 * @var ZgwRegisterAccess&MockObject
	 */
	private ZgwRegisterAccess $registers;

	/**
	 * App config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->api       = $this->createMock(ZgwApiClient::class);
		$this->registers = $this->createMock(ZgwRegisterAccess::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * Build the client under test.
	 *
	 * @return AcClient
	 */
	private function ac(): AcClient {
		return new AcClient($this->api, $this->registers, $this->appConfig, $this->createMock(LoggerInterface::class));
	}//end ac()

	/**
	 * Without an endpoint id or a scope the answer is no, and nothing is looked up.
	 *
	 * @return void
	 */
	public function testNoEndpointIdOrScopeIsRefusedWithoutALookup(): void {
		$this->registers->expects(self::never())->method('findClientForEndpoint');
		$this->api->expects(self::never())->method('callComponent');
		$ac = $this->ac();
		$ac->primeCache('ep-1', ['*' => ['zaken.lezen', '']]);

		self::assertFalse($ac->hasScope(['componenten' => ['ac' => 'https://ac']], 'https://zt/1', 'zaken.lezen'));
		self::assertFalse($ac->hasScope(['id' => 'ep-1'], 'https://zt/1', ''));
		self::assertSame([], $ac->getScopesFor(['componenten' => []], 'https://zt/1'));
	}//end testNoEndpointIdOrScopeIsRefusedWithoutALookup()

	/**
	 * A cold cache refreshes from the AC for the endpoint's client, then answers from it.
	 *
	 * @return void
	 */
	public function testAColdCacheRefreshesFromTheAcAndAnswers(): void {
		$endpoint = ['@self' => ['slug' => 'ep-slug'], 'componenten' => ['ac' => 'https://ac.example']];
		$client   = ['clientIdentifier' => 'pipelinq'];
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->registers->expects(self::once())
			->method('findClientForEndpoint')
			->with($endpoint)
			->willReturn($client);
		$this->api->expects(self::once())
			->method('callComponent')
			->with('https://ac.example', 'GET', '/autorisaties', $client)
			->willReturn(['body' => ['results' => [['clientIds' => ['pipelinq'], 'caseType' => 'https://zt/1', 'scopes' => ['zaken.aanmaken']]]]]);

		$ac = $this->ac();
		self::assertTrue($ac->hasScope($endpoint, 'https://zt/1', 'zaken.aanmaken'));
		// The second answer comes from the cache: no second lookup.
		self::assertFalse($ac->hasScope($endpoint, 'https://zt/1', 'zaken.verwijderen'));
		self::assertSame(['zaken.aanmaken'], $ac->getScopesFor($endpoint, 'https://zt/1'));
	}//end testAColdCacheRefreshesFromTheAcAndAnswers()

	/**
	 * A cold cache with no client for the endpoint fails closed.
	 *
	 * @return void
	 */
	public function testAColdCacheWithoutAClientFailsClosed(): void {
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->registers->expects(self::once())->method('findClientForEndpoint')->willReturn(null);
		$this->api->expects(self::never())->method('callComponent');

		$ac = $this->ac();
		self::assertFalse($ac->hasScope(['id' => 'ep-cold'], '*', 'zaken.lezen'));
		self::assertSame([], $ac->getScopesFor(['id' => 'ep-cold'], '*'));
	}//end testAColdCacheWithoutAClientFailsClosed()

	/**
	 * A non-positive configured interval falls back to the 900 second default.
	 *
	 * A cache 1000 seconds old is past one default interval, so a refresh is
	 * tried, and within two, so it still answers.
	 *
	 * @return void
	 */
	public function testANonPositiveIntervalFallsBackToTheDefault(): void {
		$this->appConfig->method('getValueInt')
			->with('pipelinq', 'zgw.ac_refresh_interval', 900)
			->willReturn(0);
		$this->registers->expects(self::once())->method('findClientForEndpoint')->willReturn(null);

		$ac = $this->ac();
		$ac->primeCache('ep-old', ['*' => ['zaken.lezen']], time() - 1000);
		self::assertTrue($ac->hasScope(['id' => 'ep-old'], 'https://zt/1', 'zaken.lezen'));
	}//end testANonPositiveIntervalFallsBackToTheDefault()

	/**
	 * A fresh cache is not refreshed.
	 *
	 * @return void
	 */
	public function testAFreshCacheIsNotRefreshed(): void {
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$this->registers->expects(self::never())->method('findClientForEndpoint');

		$ac = $this->ac();
		$ac->primeCache('ep-fresh', ['https://zt/1' => ['zaken.lezen']], time() - 60);
		self::assertTrue($ac->hasScope(['id' => 'ep-fresh'], 'https://zt/1', 'zaken.lezen'));
	}//end testAFreshCacheIsNotRefreshed()

	/**
	 * require() returns quietly when the scope is granted.
	 *
	 * @return void
	 */
	public function testRequirePassesAGrantedScope(): void {
		$this->appConfig->method('getValueInt')->willReturnArgument(2);
		$ac = $this->ac();
		$ac->primeCache('ep-ok', ['https://zt/1' => ['zaken.aanmaken']]);

		$ac->require(['id' => 'ep-ok'], 'https://zt/1', 'zaken.aanmaken');
		self::assertTrue($ac->hasScope(['id' => 'ep-ok'], 'https://zt/1', 'zaken.aanmaken'));
	}//end testRequirePassesAGrantedScope()
}//end class
