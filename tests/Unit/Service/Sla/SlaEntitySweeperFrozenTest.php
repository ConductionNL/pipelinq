<?php

/**
 * The SLA deadline sweep and locked records (platform-record-lock 1.3).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Sla
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Sla;

use DateTimeImmutable;
use OCA\Pipelinq\Service\ComplaintSlaService;
use OCA\Pipelinq\Service\Sla\SlaEntitySweeper;
use OCA\Pipelinq\Service\Sla\SlaSweepRun;
use OCA\Pipelinq\Service\Sla\SlaSweepSurface;
use OCA\Pipelinq\Service\SlaEngineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A locked record is skipped: not escalated, not written, and the run goes on.
 *
 * @covers \OCA\Pipelinq\Service\Sla\SlaEntitySweeper
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweepRun
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweepSurface
 * @uses   \OCA\Pipelinq\Service\Sla\SlaSweptObject
 * @uses   \OCA\Pipelinq\Service\Sla\MergesBreachEvents
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */
class SlaEntitySweeperFrozenTest extends TestCase {

	/**
	 * A ticket row with an active SLA, as an object with getObject/getUuid/isFrozen.
	 *
	 * @param string $uuid   The uuid.
	 * @param bool   $frozen Whether it is locked.
	 *
	 * @return object
	 */
	private static function ticket(string $uuid, bool $frozen): object {
		return new class($uuid, $frozen) {
			public function __construct(private string $uuid, private bool $frozen) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}

			public function isFrozen(): bool {
				return $this->frozen;
			}

			public function getObject(): array {
				return ['title' => 'Ticket ' . $this->uuid, 'slaStatus' => ['policyId' => 'P1', 'targets' => [], 'currentEscalationLevel' => 0]];
			}
		};
	}

	/**
	 * Sweep one surface of rows; return the saved uuids and the escalated ones.
	 *
	 * @param list<mixed> $rows The rows the object service lists.
	 *
	 * @return array{totals: array{processed: int, escalated: int}, saved: list<string>, escalated: list<string>, logged: list<string>}
	 */
	private function sweep(array $rows): array {
		$saved = [];
		$escalatedIds = [];
		$objectService = new class($rows, $saved) {
			public function __construct(private array $rows, private array &$saved) {
			}

			public function findAll(array $config): array {
				return $config['offset'] === 0 ? $this->rows : [];
			}

			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): void {
				if (str_starts_with($uuid, 'refused') === true) {
					throw new RuntimeException('This object is frozen.');
				}

				$this->saved[] = $uuid;
			}
		};

		$engine = $this->createMock(SlaEngineService::class);
		$engine->method('evaluateTargets')->willReturnArgument(0);
		$engine->method('executeEscalations')->willReturnCallback(
			function (array $policy, string $type, string $uuid) use (&$escalatedIds): array {
				$escalatedIds[] = $uuid;
				return ['level' => 1, 'eventIds' => ['e-' . $uuid]];
			}
		);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message) use (&$logged): void {
			$logged[] = $message;
		});
		$logger->method('warning')->willReturnCallback(function (string $message) use (&$logged): void {
			$logged[] = $message;
		});

		$run = new SlaSweepRun(register: 'reg', policies: ['P1' => ['id' => 'P1']], now: new DateTimeImmutable('2026-11-14T10:00:00Z'), objectService: $objectService);
		$totals = (new SlaEntitySweeper($engine, $this->createMock(ComplaintSlaService::class), $logger))
			->sweepAll([new SlaSweepSurface(run: $run, schemaId: 'ticket', type: 'request', extraFilters: [])]);

		return ['totals' => $totals, 'saved' => $saved, 'escalated' => $escalatedIds, 'logged' => $logged];
	}

	public function testALockedRecordIsNeitherEscalatedNorWrittenAndTheRunGoesOn(): void {
		$result = $this->sweep([self::ticket('open-1', false), self::ticket('locked-1', true), self::ticket('open-2', false)]);

		$this->assertSame(['open-1', 'open-2'], $result['escalated']);
		$this->assertSame(['open-1', 'open-2'], $result['saved']);
		$this->assertSame(['processed' => 3, 'escalated' => 2], $result['totals']);
		$this->assertContains('SlaDeadlineSweepJob: skipped a locked record', $result['logged']);
	}

	public function testARowWhoseSelfCarriesTheLockIsSkippedToo(): void {
		$row = ['uuid' => 'locked-2', '@self' => ['frozen' => ['by' => 'anna', 'at' => '2026-11-13T09:00:00Z']], 'slaStatus' => ['policyId' => 'P1', 'targets' => []]];
		$unlocked = ['uuid' => 'open-3', '@self' => ['frozen' => []], 'slaStatus' => ['policyId' => 'P1', 'targets' => []]];

		$result = $this->sweep([$row, $unlocked]);

		$this->assertSame(['open-3'], $result['escalated']);
		$this->assertSame(['open-3'], $result['saved']);
	}

	public function testAWriteRefusedForAnotherReasonIsLoggedAndTheRunGoesOn(): void {
		$result = $this->sweep([self::ticket('refused-1', false), self::ticket('open-4', false)]);

		$this->assertSame(['open-4'], $result['saved']);
		$this->assertSame(['processed' => 2, 'escalated' => 1], $result['totals']);
		$this->assertContains('SlaDeadlineSweepJob: per-object processing failed', $result['logged']);
	}
}
