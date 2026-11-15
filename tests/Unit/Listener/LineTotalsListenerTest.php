<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\LineTotalsListener;
use OCA\Pipelinq\Service\LineTotals\LineRollUp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Created, changed and deleted objects reach the roll-up; a failure is logged.
 */
class LineTotalsListenerTest extends TestCase {
	public function testCreatedChangedAndDeletedReachTheRollUp(): void {
		$line   = new ObjectEntity();
		$rollUp = $this->createMock(originalClassName: LineRollUp::class);
		$rollUp->expects($this->exactly(3))->method('apply')->with($line);
		$listener = new LineTotalsListener(rollUp: $rollUp, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$listener->handle(event: new ObjectCreatedEvent($line));
		$listener->handle(event: new ObjectUpdatedEvent($line, new ObjectEntity()));
		$listener->handle(event: new ObjectDeletedEvent($line));
	}

	public function testAnotherEventIsIgnored(): void {
		$rollUp = $this->createMock(originalClassName: LineRollUp::class);
		$rollUp->expects($this->never())->method('apply');
		(new LineTotalsListener(rollUp: $rollUp, logger: $this->createMock(originalClassName: LoggerInterface::class)))->handle(event: new ObjectCreatingEvent(new ObjectEntity()));
	}

	public function testAFailedRollUpIsLoggedNotThrown(): void {
		$rollUp = $this->createMock(originalClassName: LineRollUp::class);
		$rollUp->method('apply')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		(new LineTotalsListener(rollUp: $rollUp, logger: $logger))->handle(event: new ObjectCreatedEvent(new ObjectEntity()));
	}
}
