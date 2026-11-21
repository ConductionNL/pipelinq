<?php

/**
 * Unit tests for RecentActivityService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\MyWork
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MyWork;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\WorklistService;
use OCA\Pipelinq\Service\MyWork\RecentActivityService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The latest audit-trail entries of the user's assigned work, newest first.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-recent-activity-on-my-items-req-mw-110
 */
#[CoversClass(RecentActivityService::class)]
class RecentActivityServiceTest extends TestCase {

	/**
	 * A work item as the worklist answers it.
	 *
	 * @param string $uuid    The object uuid.
	 * @param string $kind    The kind.
	 * @param string $title   The title.
	 * @param string $updated When the object last changed.
	 *
	 * @return array<string, mixed> The item.
	 */
	private static function item(string $uuid, string $kind, string $title, string $updated): array {
		$routeName = 'TicketDetail';
		if ($kind === 'lead') {
			$routeName = 'LeadDetail';
		}

		return [
			'kind'      => $kind,
			'id'        => $uuid,
			'title'     => $title,
			'routeName' => $routeName,
			'record'    => ['id' => $uuid, '@self' => ['id' => $uuid, 'updated' => $updated]],
		];
	}//end item()

	/**
	 * A service over the given items and audit trails.
	 *
	 * @param array<int, array<string, mixed>> $items  The user's work items.
	 * @param array<string, mixed>             $trails The audit-trail rows per uuid; a RuntimeException value throws.
	 * @param array<int, string>               $read   Collects the uuids whose trail was read.
	 *
	 * @return RecentActivityService The service.
	 */
	private function service(array $items, array $trails, array &$read=[]): RecentActivityService {
		$worklist = $this->createMock(originalClassName: WorklistService::class);
		$worklist->expects($this->once())->method('items')->with('daan')->willReturn(['items' => $items, 'total' => count($items)]);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('getLogs')->willReturnCallback(
			static function (string $uuid) use ($trails, &$read): array {
				$read[] = $uuid;
				$trail = ($trails[$uuid] ?? []);
				if ($trail instanceof RuntimeException) {
					throw $trail;
				}

				return $trail;
			}
		);

		return new RecentActivityService(
			worklist: $worklist,
			objectService: $objects,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * A colleague's status change two hours ago lists the ticket, who did it and what changed.
	 *
	 * @return void
	 */
	public function testAStatusChangeOnMyTicket(): void {
		$entries = $this->service(
			items: [self::item(uuid: 't1', kind: 'request', title: 'Parking permit', updated: '2026-11-21T07:00:00+00:00')],
			trails: [
				't1' => [
					[
						'uuid'     => 'a1',
						'action'   => 'update',
						'user'     => 'sanne',
						'userName' => 'Sanne de Vries',
						'created'  => '2026-11-21T07:00:00+00:00',
						'changed'  => ['status' => ['old' => 'new', 'new' => 'in_progress'], '@self' => []],
					],
				],
			]
		)->forUser(userId: 'daan');

		$this->assertSame(
			expected: [
				[
					'id'        => 'a1',
					'action'    => 'update',
					'changes'   => 'status',
					'user'      => 'sanne',
					'userName'  => 'Sanne de Vries',
					'created'   => '2026-11-21T07:00:00+00:00',
					'title'     => 'Parking permit',
					'kind'      => 'request',
					'routeName' => 'TicketDetail',
					'recordId'  => 't1',
				],
			],
			actual: $entries
		);
	}//end testAStatusChangeOnMyTicket()

	/**
	 * Entries of several items come newest first, compared as moments, not as text.
	 *
	 * @return void
	 */
	public function testNewestFirstAcrossItems(): void {
		$entries = $this->service(
			items: [
				self::item(uuid: 't1', kind: 'request', title: 'Parking permit', updated: '2026-11-20T10:00:00+00:00'),
				self::item(uuid: 'l1', kind: 'lead', title: 'Solar panels', updated: '2026-11-21T08:00:00+00:00'),
			],
			trails: [
				't1' => [['uuid' => 'a1', 'action' => 'create', 'created' => '2026-11-20T10:00:00+00:00']],
				// 09:30 at +02:00 is 07:30 UTC, after 07:00 UTC but before it as text.
				'l1' => [
					['uuid' => 'a2', 'action' => 'update', 'created' => '2026-11-21T07:00:00+00:00'],
					['uuid' => 'a3', 'action' => 'update', 'created' => '2026-11-21T09:30:00+02:00'],
				],
			]
		)->forUser(userId: 'daan');

		$this->assertSame(expected: ['a3', 'a2', 'a1'], actual: array_column($entries, 'id'));
		$this->assertSame(expected: 'LeadDetail', actual: $entries[0]['routeName']);
	}//end testNewestFirstAcrossItems()

	/**
	 * At most ten entries, and only the twenty most recently changed items are read.
	 *
	 * @return void
	 */
	public function testTenEntriesFromTheTwentyLatestItems(): void {
		$items = [];
		$trails = [];
		for ($n = 1; $n <= 25; $n++) {
			$uuid = sprintf('t%02d', $n);
			$moment = sprintf('2026-11-%02dT09:00:00+00:00', $n);
			$items[] = self::item(uuid: $uuid, kind: 'request', title: 'Ticket '.$n, updated: $moment);
			$trails[$uuid] = [['uuid' => 'a'.$uuid, 'action' => 'update', 'created' => $moment]];
		}

		$read = [];
		$entries = $this->service(items: $items, trails: $trails, read: $read)->forUser(userId: 'daan');

		$this->assertCount(expectedCount: RecentActivityService::LIMIT, haystack: $entries);
		$this->assertSame(expected: 'at25', actual: $entries[0]['id']);
		$this->assertCount(expectedCount: RecentActivityService::ITEMS_READ, haystack: $read);
		$this->assertNotContains(needle: 't01', haystack: $read);
	}//end testTenEntriesFromTheTwentyLatestItems()

	/**
	 * An item whose trail cannot be read is left out; the others still show.
	 *
	 * @return void
	 */
	public function testABrokenTrailIsLeftOut(): void {
		$entries = $this->service(
			items: [
				self::item(uuid: 't1', kind: 'request', title: 'Broken', updated: '2026-11-21T08:00:00+00:00'),
				self::item(uuid: 't2', kind: 'request', title: 'Fine', updated: '2026-11-20T08:00:00+00:00'),
			],
			trails: [
				't1' => new RuntimeException('gone'),
				't2' => [['uuid' => 'a2', 'action' => 'update', 'created' => '2026-11-20T08:00:00+00:00']],
			]
		)->forUser(userId: 'daan');

		$this->assertSame(expected: ['Fine'], actual: array_column($entries, 'title'));
	}//end testABrokenTrailIsLeftOut()

	/**
	 * An audit-trail entity is read through its JSON form, a DateTime as ISO 8601,
	 * and a missing user name falls back to the uid.
	 *
	 * @return void
	 */
	public function testAnEntityRowWithADateTime(): void {
		$row = new class implements JsonSerializable {

			/**
			 * The row as OpenRegister's AuditTrail serialises it.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'a9', 'action' => 'update', 'user' => 'sanne', 'created' => new DateTime('2026-11-21T07:00:00+00:00')];
			}//end jsonSerialize()
		};

		$entries = $this->service(
			items: [self::item(uuid: 't1', kind: 'request', title: 'Parking permit', updated: '2026-11-21T07:00:00+00:00')],
			trails: ['t1' => [$row]]
		)->forUser(userId: 'daan');

		$this->assertSame(expected: '2026-11-21T07:00:00+00:00', actual: $entries[0]['created']);
		$this->assertSame(expected: 'sanne', actual: $entries[0]['userName']);
		$this->assertSame(expected: '', actual: $entries[0]['changes']);
	}//end testAnEntityRowWithADateTime()

	/**
	 * Without assigned work there is nothing to read and nothing to list.
	 *
	 * @return void
	 */
	public function testNoWorkNoActivity(): void {
		$read = [];
		$this->assertSame(expected: [], actual: $this->service(items: [], trails: [], read: $read)->forUser(userId: 'daan'));
		$this->assertSame(expected: [], actual: $read);
	}//end testNoWorkNoActivity()
}//end class
