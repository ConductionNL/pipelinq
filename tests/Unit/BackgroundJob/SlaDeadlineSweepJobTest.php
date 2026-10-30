<?php

/**
 * The SLA sweep leaves a locked (frozen) ticket alone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\BackgroundJob\SlaDeadlineSweepJob;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Pipelinq\BackgroundJob\SlaDeadlineSweepJob
 */
class SlaDeadlineSweepJobTest extends TestCase {
	/**
	 * The engine stub.
	 *
	 * @var SlaEngineService&MockObject
	 */
	private SlaEngineService&MockObject $engine;

	/**
	 * The job under test.
	 *
	 * @var SlaDeadlineSweepJob
	 */
	private SlaDeadlineSweepJob $job;

	/**
	 * Build the job with stubbed collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('300');

		$this->engine = $this->createMock(SlaEngineService::class);
		$this->engine->method('evaluateTargets')->willReturn([]);
		$this->engine->method('executeEscalations')->willReturn(['level' => 0, 'eventIds' => []]);

		$this->job = new SlaDeadlineSweepJob(
			$this->createMock(ITimeFactory::class),
			$this->engine,
			$this->createMock(ComplaintSlaService::class),
			$this->createMock(TicketService::class),
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A ticket row with a running SLA timer, frozen or not.
	 *
	 * @param bool $frozen Whether the ticket is locked.
	 *
	 * @return object The row as the object service hands it out.
	 */
	private function ticket(bool $frozen): object {
		return new class($frozen) {
			/**
			 * @param bool $frozen Whether the ticket is locked.
			 */
			public function __construct(
				private bool $frozen,
			) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return ['status' => 'resolved', 'slaStatus' => ['policyId' => 'p1', 'targets' => []]];
			}

			/**
			 * @return string
			 */
			public function getUuid(): string {
				return 'ticket-1';
			}

			/**
			 * @return bool
			 */
			public function isFrozen(): bool {
				return $this->frozen;
			}
		};
	}//end ticket()

	/**
	 * An object service that records every save.
	 *
	 * @return object The recorder.
	 */
	private function objectService(): object {
		return new class {
			/**
			 * The saves, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object The object data.
			 * @param array<int, string> $extend Ignored.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string $uuid The object id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				unset($extend);
				return $object;
			}
		};
	}//end objectService()

	/**
	 * Run the private per-row step.
	 *
	 * @param object $entity The row.
	 * @param object $objectService The object service.
	 *
	 * @return bool Whether an escalation fired.
	 */
	private function process(object $entity, object $objectService): bool {
		$method = new ReflectionMethod(SlaDeadlineSweepJob::class, 'processEntity');

		return (bool)$method->invoke(
			$this->job,
			$entity,
			'request',
			['p1' => ['id' => 'p1']],
			new DateTimeImmutable('2026-10-30T12:00:00+00:00'),
			'reg',
			'schema',
			$objectService,
		);
	}//end process()

	/**
	 * An unlocked ticket with a running timer is written back.
	 *
	 * @return void
	 */
	public function testAnUnlockedTicketIsWrittenBack(): void {
		$objectService = $this->objectService();

		$this->assertFalse($this->process(entity: $this->ticket(frozen: false), objectService: $objectService));
		$this->assertCount(1, $objectService->saved);
		$this->assertSame('ticket-1', $objectService->saved[0]['uuid']);
	}//end testAnUnlockedTicketIsWrittenBack()

	/**
	 * A locked ticket is skipped: no write, no escalation, the run goes on.
	 *
	 * @return void
	 */
	public function testALockedTicketIsLeftAlone(): void {
		$objectService = $this->objectService();
		$this->engine->expects($this->never())->method('executeEscalations');

		$this->assertFalse($this->process(entity: $this->ticket(frozen: true), objectService: $objectService));
		$this->assertSame([], $objectService->saved);
	}//end testALockedTicketIsLeftAlone()
}//end class
