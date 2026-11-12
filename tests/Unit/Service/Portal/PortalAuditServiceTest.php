<?php

/**
 * Unit tests for PortalAuditService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Audit-event records, their failure handling and the sorted reads.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalAuditService
 */
class PortalAuditServiceTest extends TestCase {
	/**
	 * Repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private PortalObjectRepository&MockObject $repository;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var PortalAuditService
	 */
	private PortalAuditService $service;

	/**
	 * Build the service with a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->logger     = $this->createMock(LoggerInterface::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): DateTime => new DateTime('2026-03-04T05:06:07+00:00')
		);

		$this->service = new PortalAuditService($this->repository, $time, $this->logger);
	}//end setUp()

	/**
	 * Promoted details become columns; the rest is kept under "details".
	 *
	 * @return void
	 */
	public function testLogPromotesKnownDetails(): void {
		$this->repository->expects($this->once())->method('save')->with(
			'portalAuditEvent',
			[
				'accountId'        => 'acc-1',
				'tenantId'         => 'acme',
				'eventType'        => 'login',
				'outcome'          => 'success',
				'occurredAt'       => '2026-03-04T05:06:07+00:00',
				'targetObjectType' => 'invoice',
				'targetObjectId'   => 'inv-9',
				'ipHash'           => 'ip',
				'userAgentHash'    => 'ua',
				'details'          => ['method' => 'password'],
			]
		);
		$this->logger->expects($this->never())->method('error');

		$this->service->log(
			'acc-1',
			'acme',
			'login',
			'success',
			[
				'targetObjectType' => 'invoice',
				'targetObjectId'   => 'inv-9',
				'ipHash'           => 'ip',
				'userAgentHash'    => 'ua',
				'method'           => 'password',
			]
		);
	}//end testLogPromotesKnownDetails()

	/**
	 * Without extra details there is no "details" key and promoted columns are null.
	 *
	 * @return void
	 */
	public function testLogWithoutDetails(): void {
		$this->repository->expects($this->once())->method('save')->with(
			'portalAuditEvent',
			[
				'accountId'        => null,
				'tenantId'         => 'default',
				'eventType'        => 'login-failed',
				'outcome'          => 'failure',
				'occurredAt'       => '2026-03-04T05:06:07+00:00',
				'targetObjectType' => null,
				'targetObjectId'   => null,
				'ipHash'           => null,
				'userAgentHash'    => null,
			]
		);

		$this->service->log(null, 'default', 'login-failed', 'failure');
	}//end testLogWithoutDetails()

	/**
	 * A failing save is logged, never thrown.
	 *
	 * @return void
	 */
	public function testLogSwallowsSaveFailure(): void {
		$this->repository->method('save')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Pipelinq portal: failed to write audit event',
			['eventType' => 'logout', 'exception' => 'db down']
		);

		$this->service->log('acc-1', 'acme', 'logout', 'success');
	}//end testLogSwallowsSaveFailure()

	/**
	 * Account and tenant reads filter on their key and sort newest first.
	 *
	 * @return void
	 */
	public function testGetForAccountAndTenantSortNewestFirst(): void {
		$rows = [
			['occurredAt' => '2026-01-01T00:00:00+00:00', 'n' => 1],
			['n' => 2],
			['occurredAt' => '2026-02-01T00:00:00+00:00', 'n' => 3],
		];
		$this->repository->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (string $schema, array $filters) use ($rows): array {
				TestCase::assertSame('portalAuditEvent', $schema);
				TestCase::assertContains($filters, [['accountId' => 'acc-1'], ['tenantId' => 'acme']]);
				return $rows;
			}
		);

		$this->assertSame([3, 1, 2], array_column($this->service->getForAccount('acc-1'), 'n'));
		$this->assertSame([3, 1, 2], array_column($this->service->getForTenant('acme'), 'n'));
	}//end testGetForAccountAndTenantSortNewestFirst()

	/**
	 * Recent events are limited (at least one) and report the full total.
	 *
	 * @return void
	 */
	public function testGetRecentLimitsAndCounts(): void {
		$this->repository->method('findAll')->with('portalAuditEvent')->willReturn(
			[
				['occurredAt' => '2026-01-01', 'n' => 1],
				['occurredAt' => '2026-03-01', 'n' => 3],
				['occurredAt' => '2026-02-01', 'n' => 2],
			]
		);

		$recent = $this->service->getRecent(2);
		$this->assertSame(3, $recent['total']);
		$this->assertSame([3, 2], array_column($recent['events'], 'n'));

		$this->assertSame([3], array_column($this->service->getRecent(0)['events'], 'n'));
		$this->assertSame([3], array_column($this->service->getRecent(-5)['events'], 'n'));
	}//end testGetRecentLimitsAndCounts()
}//end class
