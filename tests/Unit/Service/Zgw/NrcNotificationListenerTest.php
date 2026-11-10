<?php

/**
 * Unit tests for NrcNotificationListener.
 *
 * Covers REQ-ZGW-007 dispatch: status notifications resolve the
 * statustype omschrijving via ZTC and update Request.status; catalogi
 * notifications call ZtcClient::invalidateCache(); and the listener
 * never re-throws (NRC would otherwise retry indefinitely).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Listener\NrcNotificationListener;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NrcNotificationListener.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */
class NrcNotificationListenerTest extends TestCase {
	/**
	 * status.create → updates the pipelinq Request.status field.
	 *
	 * @return void
	 */
	public function testStatusCreatedUpdatesRequestStatus(): void {
		$endpoint = ['id' => 'zgw-ep-zoetermeer-openzaak', 'componenten' => ['ztc' => 'https://ztc/']];
		$caseUrl = 'https://open-zaak.zoetermeer.nl/zaken/api/v1/zaken/abc';
		$statusUrl = 'https://open-zaak.zoetermeer.nl/zaken/api/v1/statussen/def';
		$statustypeUrl = 'https://ztc/statustypen/xyz';

		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturnCallback(
			static function (string $schema, string $id) use ($endpoint): ?array {
				if ($schema === ZgwRegisterAccess::SCHEMA_ENDPOINT) {
					return $endpoint;
				}
				if ($schema === 'request' && $id === 'req-2026-evenement-0456') {
					return ['id' => 'req-2026-evenement-0456', 'status' => 'Aanvraag ingediend'];
				}
				return null;
			}
		);
		$registers->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use ($caseUrl): array {
				if ($schema === ZgwRegisterAccess::SCHEMA_MAPPING && ($filters['zgwUrl'] ?? '') === $caseUrl) {
					return [[
						'@self' => ['uuid' => 'map-1'],
						'pipelinqId' => 'req-2026-evenement-0456',
						'zgwUrl' => $caseUrl,
						'zgwResourceType' => 'zaak',
						'endpointId' => 'zgw-ep-zoetermeer-openzaak',
					]];
				}
				return [];
			}
		);

		$savedRequest = null;
		$registers->method('save')->willReturnCallback(
			function (string $schema, array $data, ?string $uuid = null) use (&$savedRequest): array {
				if ($schema === 'request') {
					$savedRequest = $data;
				}
				return $data;
			}
		);

		$zrc = $this->createMock(ZrcClient::class);
		$zrc->method('getStatus')->willReturn(['statustype' => $statustypeUrl]);

		$ztc = $this->createMock(ZtcClient::class);
		$ztc->method('resolveOmschrijvingFromUrl')->with($endpoint, $statustypeUrl)->willReturn('In behandeling');

		$listener = new NrcNotificationListener($registers,
			$zrc,
			$ztc,
			$this->createMock(LoggerInterface::class)
		);

		$listener->dispatch(
			['endpointId' => 'zgw-ep-zoetermeer-openzaak', 'callbackAuth' => 'bearer'],
			['channel' => 'zaken', 'resource' => 'status', 'action' => 'create', 'hoofdObject' => $caseUrl, 'resourceUrl' => $statusUrl]
		);

		self::assertIsArray($savedRequest);
		self::assertSame('In behandeling', $savedRequest['status']);
	}//end testStatusCreatedUpdatesRequestStatus()

	/**
	 * catalogi notification invalidates ZTC cache.
	 *
	 * @return void
	 */
	public function testCatalogiNotificationInvalidatesZtcCache(): void {
		$endpoint = ['id' => 'zgw-ep-zoetermeer-openzaak'];
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturn($endpoint);

		$zrc = $this->createMock(ZrcClient::class);
		$ztc = $this->createMock(ZtcClient::class);
		$ztc->expects(self::once())
			->method('invalidateCache')
			->with($endpoint, ZtcClient::RESOURCE_ZAAKTYPE);

		$listener = new NrcNotificationListener($registers,
			$zrc,
			$ztc,
			$this->createMock(LoggerInterface::class)
		);

		$listener->dispatch(
			['endpointId' => 'zgw-ep-zoetermeer-openzaak'],
			['channel' => 'catalogi', 'resource' => 'caseType', 'action' => 'update']
		);
	}//end testCatalogiNotificationInvalidatesZtcCache()

	/**
	 * unknown endpoint → log + return; no further work.
	 *
	 * @return void
	 */
	public function testUnknownEndpointIsLoggedAndDropped(): void {
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturn(null);

		$zrc = $this->createMock(ZrcClient::class);
		$ztc = $this->createMock(ZtcClient::class);
		$ztc->expects(self::never())->method('invalidateCache');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::atLeastOnce())->method('warning');

		$listener = new NrcNotificationListener($registers, $zrc, $ztc, $logger);
		$listener->dispatch(
			['endpointId' => 'no-such-endpoint'],
			['channel' => 'zaken', 'resource' => 'status', 'action' => 'create']
		);
	}//end testUnknownEndpointIsLoggedAndDropped()

	/**
	 * Handler exception is swallowed (REQ-ZGW-007 — no retries).
	 *
	 * @return void
	 */
	public function testHandlerExceptionIsSwallowed(): void {
		$endpoint = ['id' => 'ep-1', 'componenten' => ['ztc' => 'https://ztc/']];
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturn($endpoint);
		$registers->method('findAll')->willReturn([
			['pipelinqId' => 'req-1', 'zgwUrl' => 'https://open-zaak/zaken/abc'],
		]);
		$zrc = $this->createMock(ZrcClient::class);
		$zrc->method('getStatus')->willThrowException(new \RuntimeException('boom'));
		$ztc = $this->createMock(ZtcClient::class);

		$listener = new NrcNotificationListener($registers,
			$zrc,
			$ztc,
			$this->createMock(LoggerInterface::class)
		);

		// Should not throw.
		$listener->dispatch(
			['endpointId' => 'ep-1'],
			['channel' => 'zaken', 'resource' => 'status', 'action' => 'create', 'hoofdObject' => 'https://open-zaak/zaken/abc', 'resourceUrl' => 'https://x']
		);
		self::assertTrue(true);
	}//end testHandlerExceptionIsSwallowed()

	/**
	 * Each channel/resource/action routes to its own handler, a route with
	 * no handler does nothing beyond marking the subscription received, and
	 * the subscription is saved under its own uuid.
	 *
	 * @param array<string, mixed> $notification The inbound payload.
	 * @param list<string>         $infos        The info messages expected, in order.
	 * @param int                  $invalidated  How often the ZTC cache is invalidated.
	 *
	 * @return void
	 *
	 * @dataProvider routeProvider
	 */
	public function testRoutesEachNotificationToItsHandler(array $notification, array $infos, int $invalidated): void {
		$endpoint = ['id' => 'ep-1'];
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturnCallback(
			static fn (string $schema, string $id): ?array => ($schema === ZgwRegisterAccess::SCHEMA_ENDPOINT && $id === 'ep-1') ? $endpoint : null
		);
		$registers->method('findAll')->willReturn([]);
		$saved = [];
		$registers->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $uuid) use (&$saved): array {
				$saved[] = [$schema, $data['endpointId'] ?? null, $uuid, isset($data['latestReceivedOn'])];
				return $data;
			}
		);

		$ztc = $this->createMock(ZtcClient::class);
		$ztc->expects(self::exactly($invalidated))->method('invalidateCache')->with($endpoint, '*');

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::never())->method('error');

		$listener = new NrcNotificationListener($registers, $this->createMock(ZrcClient::class), $ztc, $logger);
		$listener->dispatch(['endpointId' => 'ep-1', '@self' => ['uuid' => 'ab-1']], $notification);

		self::assertSame([[ZgwRegisterAccess::SCHEMA_ABONN, 'ep-1', 'ab-1', true]], $saved);
		self::assertSame($infos, $logged);
	}//end testRoutesEachNotificationToItsHandler()

	/**
	 * The routes.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: int}>
	 */
	public static function routeProvider(): array {
		$url = ['hoofdObject' => 'https://zrc/zaken/1', 'resourceUrl' => 'https://zrc/x/1'];
		return [
			'zaak created' => [['channel' => 'zaken', 'resource' => 'zaak', 'action' => 'create'] + $url, ['ZGW NRC: observed externally-created zaak'], 0],
			'besluit created' => [['channel' => 'besluiten', 'resource' => 'besluit', 'action' => 'create'] + $url, ['ZGW NRC: observed externally-created besluit'], 0],
			'catalogi any resource' => [['channel' => 'catalogi', 'resource' => 'other', 'action' => 'destroy'], [], 1],
			'zaak updated' => [['channel' => 'zaken', 'resource' => 'zaak', 'action' => 'update'] + $url, [], 0],
			'besluit on zaken channel' => [['channel' => 'zaken', 'resource' => 'besluit', 'action' => 'create'] + $url, [], 0],
			'nothing' => [[], [], 0],
		];
	}//end routeProvider()

	/**
	 * A subscription without endpoint is dropped without a lookup, and a
	 * handler failure is logged with its route.
	 *
	 * @return void
	 */
	public function testMissingEndpointAndHandlerFailureAreLogged(): void {
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->expects(self::never())->method('find');
		$registers->expects(self::never())->method('save');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('ZGW NRC: notification received for unknown endpoint', ['endpoint' => '']);
		$listener = new NrcNotificationListener($registers, $this->createMock(ZrcClient::class), $this->createMock(ZtcClient::class), $logger);
		$listener->dispatch([], ['channel' => 'catalogi']);

		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturn(['id' => 'ep-1']);
		$registers->expects(self::once())->method('save')->with(ZgwRegisterAccess::SCHEMA_ABONN, self::anything(), 'id-7');
		$ztc = $this->createMock(ZtcClient::class);
		$ztc->method('invalidateCache')->willThrowException(new \RuntimeException('cache down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$logger->expects(self::once())->method('error')->with(
			'ZGW NRC: dispatch handler threw',
			['channel' => 'catalogi', 'resource' => 'statustype', 'action' => '', 'err' => 'cache down']
		);
		$listener = new NrcNotificationListener($registers, $this->createMock(ZrcClient::class), $ztc, $logger);
		$listener->dispatch(['endpointId' => 'ep-1', 'id' => 'id-7'], ['channel' => 'catalogi', 'resource' => 'statustype']);
	}//end testMissingEndpointAndHandlerFailureAreLogged()

	/**
	 * Each way a status notification stops short of updating a Request.
	 *
	 * @param array<string, mixed>        $notification The inbound payload extras.
	 * @param list<array<string, mixed>>  $mappings     The case mappings found.
	 * @param array<string, mixed>|null   $status       The fetched status, null to throw.
	 * @param list<string>                $expected     The calls expected, in order.
	 *
	 * @return void
	 *
	 * @dataProvider statusStopProvider
	 */
	public function testStatusCreatedStopsShort(array $notification, array $mappings, ?array $status, array $expected): void {
		$calls = new \ArrayObject();
		$registers = $this->createMock(ZgwRegisterAccess::class);
		$registers->method('find')->willReturnCallback(
			static function (string $schema, string $id) use ($calls): ?array {
				if ($schema === ZgwRegisterAccess::SCHEMA_ENDPOINT) {
					return ['id' => 'ep-1'];
				}

				$calls[] = 'find:' . $schema . ':' . $id;
				return null;
			}
		);
		$registers->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use ($calls, $mappings): array {
				$calls[] = 'findAll:' . $schema . ':' . $filters['zgwUrl'];
				return $mappings;
			}
		);
		$registers->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $uuid = null) use ($calls): array {
				$calls[] = 'save:' . $schema;
				return $data;
			}
		);
		$zrc = $this->createMock(ZrcClient::class);
		$zrc->method('getStatus')->willReturnCallback(
			static function (array $endpoint, string $url) use ($calls, $status): array {
				$calls[] = 'getStatus:' . $url;
				if ($status === null) {
					throw new \RuntimeException('zrc down');
				}

				return $status;
			}
		);
		$ztc = $this->createMock(ZtcClient::class);
		$ztc->method('resolveOmschrijvingFromUrl')->willReturnCallback(
			static function (array $endpoint, string $url) use ($calls): ?string {
				$calls[] = 'resolve:' . $url;
				return str_contains($url, 'known') ? 'Afgerond' : null;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use ($calls): void {
				$calls[] = 'warning:' . $message . ':' . ($context['err'] ?? '');
			}
		);
		$logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use ($calls): void {
				$calls[] = 'info:' . $message . ':' . ($context['id'] ?? '');
			}
		);

		$listener = new NrcNotificationListener($registers, $zrc, $ztc, $logger);
		$listener->dispatch(['endpointId' => 'ep-1'], ['channel' => 'zaken', 'resource' => 'status', 'action' => 'create'] + $notification);

		self::assertSame($expected, $calls->getArrayCopy());
	}//end testStatusCreatedStopsShort()

	/**
	 * The stop cases.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: array<string, mixed>|null, 3: list<string>}>
	 */
	public static function statusStopProvider(): array {
		$urls = ['resourceUrl' => 'https://zrc/statussen/1', 'hoofdObject' => 'https://zrc/zaken/1'];
		$mapped = [['pipelinqId' => 'req-1'], ['pipelinqId' => 'req-2']];
		$found = ['findAll:zgwResourceMapping:https://zrc/zaken/1', 'getStatus:https://zrc/statussen/1'];
		return [
			'no status url' => [['hoofdObject' => 'https://zrc/zaken/1'], $mapped, [], ['save:nrcAbonnement']],
			'no case url' => [['resourceUrl' => 'https://zrc/statussen/1'], $mapped, [], ['save:nrcAbonnement']],
			'no mapping' => [$urls, [], [], ['save:nrcAbonnement', 'findAll:zgwResourceMapping:https://zrc/zaken/1']],
			'status fetch fails' => [$urls, $mapped, null, array_merge(['save:nrcAbonnement'], $found, ['warning:ZGW NRC: failed to GET status:zrc down'])],
			'no statustype' => [$urls, $mapped, ['statustype' => ''], array_merge(['save:nrcAbonnement'], $found)],
			'unresolved statustype' => [$urls, $mapped, ['statustype' => 'https://ztc/other'], array_merge(['save:nrcAbonnement'], $found, ['resolve:https://ztc/other'])],
			'mapping without request' => [$urls, [['zgwUrl' => 'x']], ['statustype' => 'https://ztc/known'], array_merge(['save:nrcAbonnement'], $found, ['resolve:https://ztc/known'])],
			'unknown request' => [$urls, $mapped, ['statustype' => 'https://ztc/known'], array_merge(['save:nrcAbonnement'], $found, ['resolve:https://ztc/known', 'find:request:req-1', 'info:ZGW NRC: status for unknown pipelinq Request:req-1'])],
		];
	}//end statusStopProvider()

}//end class
