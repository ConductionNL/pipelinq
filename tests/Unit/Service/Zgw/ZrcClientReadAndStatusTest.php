<?php

/**
 * Unit tests for ZrcClient's read and status paths.
 *
 * Covers getZaak (ETag refresh onto the resource mapping), addStatus,
 * getStatus and the client/component guards that refuse an endpoint
 * before any HTTP call is made.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCA\Pipelinq\Service\Zgw\ZgwException;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZrcClient getZaak / addStatus / getStatus.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\ZrcClient
 * @uses   \OCA\Pipelinq\Service\Zgw\ZgwException
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 */
class ZrcClientReadAndStatusTest extends TestCase {
	private const ZRC = 'https://open-zaak.zoetermeer.nl/zaken/api/v1';
	private const ZAAK_URL = self::ZRC . '/zaken/5b1c2d3e-0000-4000-8000-000000000001';

	/**
	 * ZgwEndpoint payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $endpoint = [
		'id' => 'zgw-ep-zoetermeer',
		'clientId' => 'zgw-client-zoetermeer',
		'componenten' => ['zrc' => self::ZRC],
	];

	/**
	 * ZgwClient payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $client = ['id' => 'zgw-client-zoetermeer', 'clientId' => 'pipelinq', 'secret' => 'x'];

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
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->api = $this->createMock(ZgwApiClient::class);
		$this->registers = $this->createMock(ZgwRegisterAccess::class);
	}//end setUp()

	/**
	 * Build the client under test.
	 *
	 * @return ZrcClient
	 */
	private function zrc(): ZrcClient {
		return new ZrcClient($this->api, $this->registers, $this->createMock(LoggerInterface::class));
	}//end zrc()

	/**
	 * Resolve the endpoint's client.
	 *
	 * @return void
	 */
	private function clientResolves(): void {
		$this->registers->method('findClientForEndpoint')->with($this->endpoint)->willReturn($this->client);
	}//end clientResolves()

	/**
	 * getZaak GETs the mapping's zgwUrl and stores the fresh ETag on the mapping found by @self.uuid.
	 *
	 * @return void
	 */
	public function testGetZaakSavesTheEtagOnTheMappingBySelfUuid(): void {
		$this->clientResolves();
		$this->api->expects(self::once())
			->method('callComponent')
			->with(self::ZAAK_URL, 'GET', '', $this->client)
			->willReturn(['status' => 200, 'headers' => ['etag' => 'W/"7"'], 'body' => ['url' => self::ZAAK_URL]]);

		$saved = [];
		$this->registers->expects(self::once())
			->method('save')
			->willReturnCallback(static function (string $schema, array $data, ?string $uuid) use (&$saved): array {
				$saved = [$schema, $data, $uuid];
				return $data;
			});

		$mapping = ['@self' => ['uuid' => 'map-1'], 'zgwUrl' => self::ZAAK_URL, 'etag' => 'W/"6"'];
		$body = $this->zrc()->getZaak($this->endpoint, $mapping);

		self::assertSame(['url' => self::ZAAK_URL], $body);
		self::assertSame('zgwResourceMapping', $saved[0]);
		self::assertSame('map-1', $saved[2]);
		self::assertSame('W/"7"', $saved[1]['etag']);
		self::assertSame(self::ZAAK_URL, $saved[1]['zgwUrl']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $saved[1]['lastSynchronisation']);
	}//end testGetZaakSavesTheEtagOnTheMappingBySelfUuid()

	/**
	 * Without @self the mapping's id is the uuid saved under.
	 *
	 * @return void
	 */
	public function testGetZaakSavesTheEtagOnTheMappingById(): void {
		$this->clientResolves();
		$this->api->method('callComponent')
			->willReturn(['status' => 200, 'headers' => ['etag' => 'W/"9"'], 'body' => []]);
		$this->registers->expects(self::once())
			->method('save')
			->with('zgwResourceMapping', self::callback(static fn (array $d): bool => $d['etag'] === 'W/"9"' && $d['id'] === 'map-2'), 'map-2')
			->willReturn([]);

		$this->zrc()->getZaak($this->endpoint, ['id' => 'map-2', 'zgwUrl' => self::ZAAK_URL]);
	}//end testGetZaakSavesTheEtagOnTheMappingById()

	/**
	 * No ETag header, or no mapping identity: nothing is written back.
	 *
	 * @return void
	 */
	public function testGetZaakWritesNothingWithoutAnEtagOrAMappingIdentity(): void {
		$this->clientResolves();
		$this->api->method('callComponent')->willReturnOnConsecutiveCalls(
			['status' => 200, 'headers' => [], 'body' => ['n' => 1]],
			['status' => 200, 'headers' => ['etag' => 'W/"1"'], 'body' => ['n' => 2]]
		);
		$this->registers->expects(self::never())->method('save');

		self::assertSame(['n' => 1], $this->zrc()->getZaak($this->endpoint, ['id' => 'map-3', 'zgwUrl' => self::ZAAK_URL]));
		self::assertSame(['n' => 2], $this->zrc()->getZaak($this->endpoint, ['zgwUrl' => self::ZAAK_URL]));
	}//end testGetZaakWritesNothingWithoutAnEtagOrAMappingIdentity()

	/**
	 * A mapping without zgwUrl is refused before the client is resolved.
	 *
	 * @return void
	 */
	public function testGetZaakRefusesAMappingWithoutUrl(): void {
		$this->registers->expects(self::never())->method('findClientForEndpoint');
		$this->api->expects(self::never())->method('callComponent');

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: mapping has no zgwUrl');

		$this->zrc()->getZaak($this->endpoint, ['id' => 'map-4']);
	}//end testGetZaakRefusesAMappingWithoutUrl()

	/**
	 * An endpoint whose clientId does not resolve is refused with both ids in the message.
	 *
	 * @return void
	 */
	public function testAnUnknownClientIsRefusedBeforeAnyCall(): void {
		$this->registers->method('findClientForEndpoint')->willReturn(null);
		$this->api->expects(self::never())->method('callComponent');

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: ZgwEndpoint "zgw-ep-zoetermeer" references unknown clientId "zgw-client-zoetermeer"');

		$this->zrc()->getStatus($this->endpoint, self::ZRC . '/statussen/1');
	}//end testAnUnknownClientIsRefusedBeforeAnyCall()

	/**
	 * The refusal message uses "?" for an endpoint without id or clientId.
	 *
	 * @return void
	 */
	public function testAnUnknownClientMessageFallsBackToQuestionMarks(): void {
		$this->registers->method('findClientForEndpoint')->willReturn(null);

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: ZgwEndpoint "?" references unknown clientId "?"');

		$this->zrc()->addStatus([], [], []);
	}//end testAnUnknownClientMessageFallsBackToQuestionMarks()

	/**
	 * addStatus POSTs /statussen on the zrc with the zaak URL first, and returns the Location.
	 *
	 * @return void
	 */
	public function testAddStatusPostsToStatussenAndReturnsTheLocation(): void {
		$this->clientResolves();
		$this->api->expects(self::once())
			->method('callComponent')
			->with(
				self::ZRC,
				'POST',
				'/statussen',
				$this->client,
				['zaak' => self::ZAAK_URL, 'statustype' => 'st-1', 'datumStatusGezet' => '2026-01-01T00:00:00Z']
			)
			->willReturn(['status' => 201, 'headers' => ['location' => self::ZRC . '/statussen/9'], 'body' => ['url' => 'ignored']]);

		$url = $this->zrc()->addStatus(
			$this->endpoint,
			['zgwUrl' => self::ZAAK_URL],
			['statustype' => 'st-1', 'datumStatusGezet' => '2026-01-01T00:00:00Z']
		);

		self::assertSame(self::ZRC . '/statussen/9', $url);
	}//end testAddStatusPostsToStatussenAndReturnsTheLocation()

	/**
	 * Status data may override the zaak; without a Location the body url is returned, else ''.
	 *
	 * @return void
	 */
	public function testAddStatusFallsBackToTheBodyUrlThenEmpty(): void {
		$this->clientResolves();
		$bodies = [];
		$this->api->method('callComponent')->willReturnCallback(
			static function (string $c, string $m, string $p, array $cl, ?array $body) use (&$bodies): array {
				$bodies[] = $body;
				if (count($bodies) === 1) {
					return ['status' => 201, 'headers' => [], 'body' => ['url' => 'https://zrc/statussen/body']];
				}
				return ['status' => 201, 'headers' => [], 'body' => []];
			}
		);

		self::assertSame('https://zrc/statussen/body', $this->zrc()->addStatus($this->endpoint, [], ['zaak' => 'https://zrc/zaken/override']));
		self::assertSame('', $this->zrc()->addStatus($this->endpoint, [], []));
		self::assertSame(['zaak' => 'https://zrc/zaken/override'], $bodies[0]);
		self::assertSame(['zaak' => ''], $bodies[1]);
	}//end testAddStatusFallsBackToTheBodyUrlThenEmpty()

	/**
	 * An endpoint without a zrc component URL is refused.
	 *
	 * @return void
	 */
	public function testAddStatusRefusesAnEndpointWithoutZrc(): void {
		$endpoint = ['id' => 'ep', 'clientId' => 'c', 'componenten' => ['ztc' => 'https://ztc']];
		$this->registers->method('findClientForEndpoint')->willReturn($this->client);
		$this->api->expects(self::never())->method('callComponent');

		$this->expectException(ZgwException::class);
		$this->expectExceptionMessage('ZGW: endpoint missing "zrc" component URL');

		$this->zrc()->addStatus($endpoint, ['zgwUrl' => self::ZAAK_URL], []);
	}//end testAddStatusRefusesAnEndpointWithoutZrc()

	/**
	 * getStatus GETs the status URL itself and returns the body.
	 *
	 * @return void
	 */
	public function testGetStatusGetsTheUrlAndReturnsTheBody(): void {
		$this->clientResolves();
		$statusUrl = self::ZRC . '/statussen/3';
		$this->api->expects(self::once())
			->method('callComponent')
			->with($statusUrl, 'GET', '', $this->client)
			->willReturn(['status' => 200, 'headers' => [], 'body' => ['url' => $statusUrl, 'statustype' => 'st-2']]);

		self::assertSame(['url' => $statusUrl, 'statustype' => 'st-2'], $this->zrc()->getStatus($this->endpoint, $statusUrl));
	}//end testGetStatusGetsTheUrlAndReturnsTheBody()
}//end class
