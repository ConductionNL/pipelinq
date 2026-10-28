<?php

/**
 * Tests for FirstTicketService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\FirstTicketService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The first ticket: most overdue, then highest priority, then oldest; done
 * tickets never count.
 *
 * @covers \OCA\Pipelinq\Service\FirstTicketService
 */
class FirstTicketServiceTest extends TestCase {
	/**
	 * The service over the given tickets (all of type request).
	 *
	 * @param array<int, array<string, mixed>> $tickets The user's tickets.
	 *
	 * @return FirstTicketService
	 */
	private function service(array $tickets): FirstTicketService {
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('findByType')->willReturnCallback(
			fn (string $ticketType) => ($ticketType === TicketService::TYPE_REQUEST ? $tickets : [])
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(null);

		return new FirstTicketService($ticketService, $objects, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Now: Wednesday 21 October 2026, noon.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-21T12:00:00+00:00');
	}//end now()

	/**
	 * The most overdue ticket wins over a higher priority one that is not overdue.
	 *
	 * @return void
	 */
	public function testTheMostOverdueTicketComesFirst(): void {
		$first = $this->service([
			['id' => 'a', 'title' => 'Urgent, on time', 'priority' => 'urgent', 'status' => 'new', 'occurredAt' => '2026-10-20T09:00:00+00:00', 'slaDeadline' => '2026-10-25T00:00:00+00:00'],
			['id' => 'b', 'title' => 'One day late', 'priority' => 'low', 'status' => 'in_progress', 'occurredAt' => '2026-10-15T09:00:00+00:00', 'slaDeadline' => '2026-10-20T12:00:00+00:00'],
			['id' => 'c', 'title' => 'Three days late', 'priority' => 'normal', 'status' => 'in_progress', 'occurredAt' => '2026-10-18T12:00:00+00:00', 'slaDeadline' => '2026-10-18T12:00:00+00:00'],
		])->firstFor(userId: 'pieter', now: $this->now());

		$this->assertSame('c', $first['id']);
		$this->assertSame(3, $first['overdueDays']);
		$this->assertSame(3, $first['waitingDays']);
	}//end testTheMostOverdueTicketComesFirst()

	/**
	 * Without an overdue ticket the highest priority wins, then the oldest.
	 *
	 * @return void
	 */
	public function testThenPriorityThenOldest(): void {
		$service = $this->service([
			['id' => 'a', 'priority' => 'normal', 'status' => 'new', 'occurredAt' => '2026-10-01T09:00:00+00:00'],
			['id' => 'b', 'priority' => 'high', 'status' => 'new', 'occurredAt' => '2026-10-20T09:00:00+00:00'],
			['id' => 'c', 'priority' => 'high', 'status' => 'new', 'occurredAt' => '2026-10-10T09:00:00+00:00'],
		]);

		$this->assertSame('c', $service->firstFor(userId: 'pieter', now: $this->now())['id']);
	}//end testThenPriorityThenOldest()

	/**
	 * Done tickets never count; with none open there is no first ticket.
	 *
	 * @return void
	 */
	public function testDoneTicketsDoNotCount(): void {
		$service = $this->service([
			['id' => 'a', 'priority' => 'urgent', 'status' => 'closed', 'slaDeadline' => '2026-10-01T00:00:00+00:00'],
			['id' => 'b', 'priority' => 'urgent', 'status' => 'resolved'],
		]);

		$this->assertNull($service->firstFor(userId: 'pieter', now: $this->now()));
	}//end testDoneTicketsDoNotCount()
}//end class
