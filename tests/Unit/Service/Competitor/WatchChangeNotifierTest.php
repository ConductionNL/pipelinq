<?php

/**
 * Tests for WatchChangeNotifier and its place in a watch run.
 *
 * The owner of a page watch is told when the watched fragment changed, and
 * only then: not for a first read, not for another kind of watch, not when
 * nothing new was recorded, and not when the watch has nobody to tell.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/specs/marketing-competitor-watches/spec.md#requirement-the-owner-of-a-watch-is-told-when-the-watched-page-changes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCA\Pipelinq\Service\Competitor\WatchChangeNotifier;
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Competitor\WatchOutcome;
use OCA\Pipelinq\Service\Competitor\WatchReaders;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\Competitor\WatchChangeNotifier
 * @covers \OCA\Pipelinq\Service\Competitor\CompetitorWatchService
 * @uses   \OCA\Pipelinq\Service\Competitor\WatchOutcome
 */
class WatchChangeNotifierTest extends TestCase {

	/**
	 * The mocked store.
	 *
	 * @var ListObjectStore&MockObject
	 */
	private ListObjectStore $store;

	/**
	 * The mocked notification service.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService $notifications;

	/**
	 * The notifier under test.
	 *
	 * @var WatchChangeNotifier
	 */
	private WatchChangeNotifier $notifier;

	/**
	 * Build the notifier over a store that knows competitor Acme.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(ListObjectStore::class);
		$this->store->method('schemaSlug')->willReturnCallback(static fn (string $configKey, string $default): string => $default);
		$this->store->method('idOf')->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));
		$this->store->method('find')->willReturnCallback(
			static fn (string $schemaSlug, string $id): ?array => ($schemaSlug === 'competitor' && $id === 'acme') ? ['id' => 'acme', 'name' => 'Acme'] : null
		);
		$this->notifications = $this->createMock(NotificationService::class);
		$this->notifier = new WatchChangeNotifier(store: $this->store, notifications: $this->notifications);
	}//end setUp()

	/**
	 * Sanne's page watch on Acme's pricing page, read before.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function pageWatch(array $extra = []): array {
		return $extra + [
			'id' => 'w-1',
			'@self' => ['owner' => 'sanne'],
			'competitorId' => 'acme',
			'kind' => 'page',
			'target' => 'https://acme.example/pricing',
			'selector' => '#prices',
			'fingerprint' => 'f-old',
		];
	}//end pageWatch()

	public function testTheOwnerIsToldThatTheCompetitorChangedItsPage(): void {
		$this->notifications->expects($this->once())->method('sendNotification')->with(
			'sanne',
			'competitor_page_changed',
			['competitor' => 'Acme', 'page' => 'https://acme.example/pricing'],
			'competitor',
			'acme'
		);

		$this->assertTrue($this->notifier->notifyChange(watch: self::pageWatch(), created: 1));
	}

	public function testAnOwnerFieldOnTheWatchIsUsedWithoutAnObjectOwner(): void {
		$this->notifications->expects($this->once())->method('sendNotification')
			->with('mark', 'competitor_page_changed', $this->anything(), 'competitor', 'acme');

		$this->assertTrue($this->notifier->notifyChange(watch: self::pageWatch(['@self' => [], 'owner' => ' mark ']), created: 1));
	}

	public function testAnUnknownCompetitorIsNamedByItsId(): void {
		$this->notifications->expects($this->once())->method('sendNotification')
			->with('sanne', 'competitor_page_changed', ['competitor' => 'globex', 'page' => 'https://acme.example/pricing'], 'competitor', 'globex');

		$this->assertTrue($this->notifier->notifyChange(watch: self::pageWatch(['competitorId' => 'globex']), created: 1));
	}

	public function testNothingIsSentWithoutAChangeOfAPageThatWasReadBefore(): void {
		$this->notifications->expects($this->never())->method('sendNotification');

		$this->assertFalse($this->notifier->notifyChange(watch: self::pageWatch(), created: 0), 'an unchanged page');
		$this->assertFalse($this->notifier->notifyChange(watch: self::pageWatch(['fingerprint' => '']), created: 1), 'a first read is a baseline');
		$this->assertFalse($this->notifier->notifyChange(watch: self::pageWatch(['kind' => 'rss']), created: 3), 'another kind of watch');
		$this->assertFalse($this->notifier->notifyChange(watch: self::pageWatch(['@self' => []]), created: 1), 'nobody to tell');
	}

	public function testARunThatRecordsAChangeTellsTheOwnerAndAnUnchangedRunDoesNot(): void {
		$readers = $this->createMock(WatchReaders::class);
		$readers->method('read')->willReturnOnConsecutiveCalls(
			WatchOutcome::seen(items: [['url' => 'https://acme.example/pricing', 'title' => 'https://acme.example/pricing', 'summary' => '+ €19', 'stamp' => 'f-new']], state: ['fingerprint' => 'f-new']),
			WatchOutcome::seen(items: [], state: ['fingerprint' => 'f-new'])
		);
		$events = $this->createMock(WatchEventStore::class);
		$events->method('upsert')->willReturn(['created' => true]);
		$scorer = $this->createMock(RelevanceScorer::class);
		$scorer->method('fieldsFor')->willReturn([]);
		$this->notifications->expects($this->once())->method('sendNotification')
			->with('sanne', 'competitor_page_changed', ['competitor' => 'Acme', 'page' => 'https://acme.example/pricing'], 'competitor', 'acme');

		$service = new CompetitorWatchService(
			store: $this->store,
			readers: $readers,
			scorer: $scorer,
			events: $events,
			time: $this->createMock(ITimeFactory::class),
			logger: $this->createMock(LoggerInterface::class),
			changeNotifier: $this->notifier
		);

		$this->assertSame(1, $service->runOne(watch: self::pageWatch())['events']);
		$this->assertSame(0, $service->runOne(watch: self::pageWatch(['fingerprint' => 'f-new']))['events']);
	}
}
