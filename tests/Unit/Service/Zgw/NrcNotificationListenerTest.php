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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-007
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-007
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

}//end class
