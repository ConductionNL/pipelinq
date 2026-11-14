<?php

/**
 * Unit tests for the calendar leaf's events on the activity timeline.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/calendar-deepening/tasks.md#4-timeline-merge
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityItemNormalizer;
use OCA\Pipelinq\Service\ActivityTimelineReader;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IDateTimeFormatter;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Leaf-linked meetings are read through on the timeline, once each, and a failing leaf is left out.
 *
 * @covers \OCA\Pipelinq\Service\ActivityTimelineService
 * @covers \OCA\Pipelinq\Service\ActivityTimelineReader
 * @covers \OCA\Pipelinq\Service\ActivityItemNormalizer
 */
class ActivityTimelineLeafEventsTest extends TestCase {

	/**
	 * The calendar leaf's service id.
	 *
	 * @var string
	 */
	private const LEAF = 'OCA\\OpenRegister\\Service\\CalendarLinkService';

	/**
	 * Rows per schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Build the service with a leaf double.
	 *
	 * @param object|null $leaf The leaf, or null for an OpenRegister without it.
	 *
	 * @return ActivityTimelineService
	 */
	private function service(?object $leaf): ActivityTimelineService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				'calendarLink_schema' => 'cal-s',
				default => $default,
			}
		);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('getSchemaId')->willReturn('');

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $params): array => $this->rows[$params['filters']['schema']] ?? []
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $leaf !== null && $id === self::LEAF);
		$container->method('get')->willReturnCallback(static fn (): ?object => $leaf);

		$logger = $this->createMock(LoggerInterface::class);

		return new ActivityTimelineService(
			appConfig: $config,
			userSession: $this->createMock(IUserSession::class),
			logger: $logger,
			ticketService: $tickets,
			reader: new ActivityTimelineReader(
				appConfig: $config,
				objectService: $objects,
				notesService: $this->createMock(NotesService::class),
				container: $container,
				logger: $logger,
			),
			itemNormalizer: new ActivityItemNormalizer(
				userManager: $this->createMock(IUserManager::class),
				dateTimeFormatter: $this->createMock(IDateTimeFormatter::class)
			),
		);
	}//end service()

	/**
	 * A leaf double returning fixed events for one object.
	 *
	 * @param array<int, mixed> $events The events.
	 *
	 * @return object The leaf.
	 */
	private function leaf(array $events): object {
		return new class($events) {
			/**
			 * Objects asked for.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $events The events.
			 */
			public function __construct(private array $events) {
			}//end __construct()

			/**
			 * The linked events.
			 *
			 * @param string $objectUuid The object.
			 *
			 * @return array<int, mixed>
			 */
			public function getLinkedEvents(string $objectUuid): array {
				$this->asked[] = $objectUuid;
				return $this->events;
			}//end getLinkedEvents()
		};
	}//end leaf()

	/**
	 * A linked meeting is an appointment item with its summary and start, past ones completed.
	 *
	 * @return void
	 */
	public function testLeafEventsAreAppointmentItems(): void {
		$leaf = $this->leaf(
			[
				['uid' => 'u-past', 'summary' => 'Intake', 'dtstart' => '2001-02-03T10:00:00+00:00', 'dtend' => '2001-02-03T11:00:00+00:00', 'location' => 'Desk 2'],
				['uid' => 'u-future', 'summary' => 'Review', 'dtstart' => '2999-01-01T09:00:00+00:00'],
				['summary' => 'No uid, not shown', 'dtstart' => '2001-01-01T09:00:00+00:00'],
				'not a row',
			]
		);

		$items = $this->service(leaf: $leaf)->getTimeline(entityType: 'client', entityId: 'client-1', params: [])['items'];

		$this->assertSame(['client-1'], $leaf->asked);
		$this->assertSame(['u-future', 'u-past'], array_column($items, 'id'));
		$this->assertSame(['Review', 'Intake'], array_column($items, 'title'));
		$this->assertSame(['calendar', 'calendar'], array_column($items, 'type'));
		$this->assertSame('2001-02-03T10:00:00+00:00', $items[1]['date']);
		$this->assertSame(
			['uid' => 'u-past', 'source' => 'calendar-leaf', 'status' => 'completed', 'endDate' => '2001-02-03T11:00:00+00:00', 'location' => 'Desk 2'],
			$items[1]['metadata']
		);
		$this->assertSame('scheduled', $items[0]['metadata']['status']);
	}//end testLeafEventsAreAppointmentItems()

	/**
	 * Regression: an event contributed by the leaf and by a stored calendar link renders once, from the leaf.
	 *
	 * @return void
	 */
	public function testTheSameEventFromTwoSourcesRendersOnce(): void {
		$this->rows['cal-s'] = [
			['id' => 'link-1', 'title' => 'Stale copy', 'eventUid' => 'u-1', 'startDate' => '2001-05-01T09:00:00+00:00'],
			['id' => 'link-2', 'title' => 'Only stored', 'eventUid' => 'u-2', 'startDate' => '2001-04-01T09:00:00+00:00'],
		];
		$leaf = $this->leaf(
			[
				['uid' => 'u-1', 'summary' => 'Live', 'dtstart' => '2001-05-02T09:00:00+00:00'],
				['uid' => 'u-1', 'summary' => 'Live, other calendar', 'dtstart' => '2001-05-02T09:00:00+00:00'],
			]
		);

		$items = $this->service(leaf: $leaf)->getTimeline(entityType: 'client', entityId: 'client-1', params: [])['items'];

		$this->assertSame(['Live', 'Only stored'], array_column($items, 'title'));
		$this->assertSame(['u-1', 'link-2'], array_column($items, 'id'));
		$this->assertSame('calendar-leaf', $items[0]['metadata']['source']);
	}//end testTheSameEventFromTwoSourcesRendersOnce()

	/**
	 * A failing leaf leaves its source out and the timeline still answers.
	 *
	 * @return void
	 */
	public function testAFailingLeafIsOmitted(): void {
		$this->rows['cal-s'] = [
			['id' => 'link-2', 'title' => 'Only stored', 'eventUid' => 'u-2', 'startDate' => '2001-04-01T09:00:00+00:00'],
		];
		$leaf = new class {
			/**
			 * Always fails.
			 *
			 * @param string $objectUuid The object.
			 *
			 * @return array<int, mixed>
			 */
			public function getLinkedEvents(string $objectUuid): array {
				throw new RuntimeException('caldav down');
			}//end getLinkedEvents()
		};

		$items = $this->service(leaf: $leaf)->getTimeline(entityType: 'client', entityId: 'client-1', params: [])['items'];

		$this->assertSame(['Only stored'], array_column($items, 'title'));
	}//end testAFailingLeafIsOmitted()

	/**
	 * Without the leaf there are no leaf items and no error.
	 *
	 * @return void
	 */
	public function testNoLeafNoLeafItems(): void {
		$this->assertSame([], $this->service(leaf: null)->getTimeline(entityType: 'client', entityId: 'client-1', params: [])['items']);
	}//end testNoLeafNoLeafItems()

	/**
	 * The calendar type filter keeps leaf items; another type drops them.
	 *
	 * @return void
	 */
	public function testTheCalendarFilterKeepsLeafItems(): void {
		$leaf = $this->leaf([['uid' => 'u-1', 'summary' => 'Live', 'dtstart' => '2001-05-02T09:00:00+00:00']]);

		$calendar = $this->service(leaf: $leaf)->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => 'calendar'])['items'];
		$notes = $this->service(leaf: $leaf)->getTimeline(entityType: 'client', entityId: 'client-1', params: ['types' => 'note'])['items'];

		$this->assertSame(['Live'], array_column($calendar, 'title'));
		$this->assertSame([], $notes);
	}//end testTheCalendarFilterKeepsLeafItems()
}//end class
