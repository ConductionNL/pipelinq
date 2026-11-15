<?php

/**
 * What NrcNotificationListener logs for externally created cases and decisions.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\NrcNotificationListener;
use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use OCA\Pipelinq\Service\Zgw\ZrcClient;
use OCA\Pipelinq\Service\Zgw\ZtcClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A created case or decision is logged as externally created only when it
 * has a url and no mapping already links it to a pipelinq object.
 *
 * @covers \OCA\Pipelinq\Listener\NrcNotificationListener
 */
class NrcNotificationListenerCreatedTest extends TestCase {
	/**
	 * The register access.
	 *
	 * @var ZgwRegisterAccess&MockObject
	 */
	private ZgwRegisterAccess&MockObject $registers;

	/**
	 * The info messages logged, with their context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $infos = [];

	/**
	 * The listener under test.
	 *
	 * @var NrcNotificationListener
	 */
	private NrcNotificationListener $listener;

	/**
	 * Build the listener with one known endpoint.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->infos = [];
		$this->registers = $this->createMock(originalClassName: ZgwRegisterAccess::class);
		$this->registers->method('find')->willReturn(['id' => 'ep-1']);
		$this->registers->method('save')->willReturnArgument(1);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->infos[] = [$message, $context];
			}
		);

		$this->listener = new NrcNotificationListener(
			registers: $this->registers,
			zrc: $this->createMock(originalClassName: ZrcClient::class),
			ztc: $this->createMock(originalClassName: ZtcClient::class),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Dispatch one notification on the known endpoint.
	 *
	 * @param array<string, string> $notification The notification.
	 *
	 * @return void
	 */
	private function dispatch(array $notification): void {
		$this->listener->dispatch(abonnement: ['endpointId' => 'ep-1'], notification: $notification);
	}//end dispatch()

	/**
	 * A created case without a url looks nothing up and logs nothing.
	 *
	 * @return void
	 */
	public function testACreatedCaseWithoutAUrlIsIgnored(): void {
		$this->registers->expects($this->never())->method('findAll');

		$this->dispatch(notification: ['channel' => 'zaken', 'resource' => 'zaak', 'action' => 'create']);

		$this->assertSame(expected: [], actual: $this->infos);
	}//end testACreatedCaseWithoutAUrlIsIgnored()

	/**
	 * A created case already mapped to pipelinq is not reported as external.
	 *
	 * @return void
	 */
	public function testAMappedCaseIsNotReportedAsExternal(): void {
		$this->registers->expects($this->once())->method('findAll')
			->with(ZgwRegisterAccess::SCHEMA_MAPPING, ['zgwUrl' => 'https://zrc/zaken/1'])
			->willReturn([['pipelinqId' => 'req-1']]);

		$this->dispatch(notification: ['channel' => 'zaken', 'resource' => 'zaak', 'action' => 'create', 'hoofdObject' => 'https://zrc/zaken/1']);

		$this->assertSame(expected: [], actual: $this->infos);
	}//end testAMappedCaseIsNotReportedAsExternal()

	/**
	 * An unmapped created case is reported by its resource url when it has no main object.
	 *
	 * @return void
	 */
	public function testAnUnmappedCaseIsReportedByItsResourceUrl(): void {
		$this->registers->method('findAll')->willReturn([]);

		$this->dispatch(notification: ['channel' => 'zaken', 'resource' => 'zaak', 'action' => 'create', 'resourceUrl' => 'https://zrc/zaken/2']);

		$this->assertSame(
			expected: [['ZGW NRC: observed externally-created zaak', ['zaak' => 'https://zrc/zaken/2']]],
			actual: $this->infos
		);
	}//end testAnUnmappedCaseIsReportedByItsResourceUrl()

	/**
	 * A created decision without a url looks nothing up and logs nothing.
	 *
	 * @return void
	 */
	public function testACreatedDecisionWithoutAUrlIsIgnored(): void {
		$this->registers->expects($this->never())->method('findAll');

		$this->dispatch(notification: ['channel' => 'besluiten', 'resource' => 'besluit', 'action' => 'create']);

		$this->assertSame(expected: [], actual: $this->infos);
	}//end testACreatedDecisionWithoutAUrlIsIgnored()

	/**
	 * A created decision already mapped to pipelinq is not reported as external.
	 *
	 * @return void
	 */
	public function testAMappedDecisionIsNotReportedAsExternal(): void {
		$this->registers->expects($this->once())->method('findAll')
			->with(ZgwRegisterAccess::SCHEMA_MAPPING, ['zgwUrl' => 'https://brc/besluiten/1'])
			->willReturn([['pipelinqId' => 'req-1']]);

		$this->dispatch(notification: ['channel' => 'besluiten', 'resource' => 'besluit', 'action' => 'create', 'resourceUrl' => 'https://brc/besluiten/1']);

		$this->assertSame(expected: [], actual: $this->infos);
	}//end testAMappedDecisionIsNotReportedAsExternal()

	/**
	 * An unmapped decision is reported by its main object when it has no resource url.
	 *
	 * @return void
	 */
	public function testAnUnmappedDecisionIsReportedByItsMainObject(): void {
		$this->registers->method('findAll')->willReturn([]);

		$this->dispatch(notification: ['channel' => 'besluiten', 'resource' => 'besluit', 'action' => 'create', 'hoofdObject' => 'https://brc/besluiten/2']);

		$this->assertSame(
			expected: [['ZGW NRC: observed externally-created besluit', ['besluit' => 'https://brc/besluiten/2']]],
			actual: $this->infos
		);
	}//end testAnUnmappedDecisionIsReportedByItsMainObject()
}//end class
