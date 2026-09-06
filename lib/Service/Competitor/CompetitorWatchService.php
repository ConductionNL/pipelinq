<?php

/**
 * Pipelinq CompetitorWatchService.
 *
 * Picks the watches that are due, runs each one through its reader, scores
 * what came back and records it.
 *
 * NOTHING SCHEDULES ANYTHING HERE. ADR-094 routes new scheduled automation to
 * OpenRegister's flow engine, so the firing belongs to a flow and this service
 * only answers "which of these is due right now" when the flow's node asks.
 * There is no `TimedJob` in this change, and {@see dueNow()} is the whole of
 * what would otherwise have been one.
 *
 * ONE FAILING WATCH DOES NOT STOP THE RUN. A competitor whose site is down,
 * a renamed div, an instance that has gone away: each of those is normal, and
 * letting the first one abort the pass would mean the other watches silently
 * stop working the day one competitor changes their sitemap.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the competitor watches that are due.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) This class is the
 *  dispatcher over the five watch readers, and a dispatcher over five
 *  things depends on five things. Each reader is independently testable
 *  and none of them knows about the others; hiding them behind a registry
 *  would move the same five dependencies one file along and cost the
 *  match expression that makes the dispatch readable.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Same reason: five
 *  readers, the scorer, the event store, the register access, a clock and
 *  a logger. Every one of them is injected so it can be replaced in a
 *  test, which is what makes the watch pass assertable without a network.
 * @SuppressWarnings(PHPMD.StaticAccess) `WatchOutcome`'s named
 *  constructors, on the same grounds as the readers themselves.
 */
class CompetitorWatchService {

	/**
	 * App-config key naming the OpenConnector source competitor reads leave
	 * through.
	 *
	 * @var string
	 */
	public const SOURCE_KEY = 'competitor.egress_source';

	/**
	 * The `competitorWatch` schema slug.
	 *
	 * @var string
	 */
	public const WATCH_SCHEMA = 'competitorWatch';

	/**
	 * The `competitor` schema slug.
	 *
	 * @var string
	 */
	public const COMPETITOR_SCHEMA = 'competitor';

	/**
	 * How many seconds each cadence means.
	 *
	 * @var array<string, int>
	 */
	public const CADENCE_SECONDS = [
		'hourly' => 3600,
		'daily' => 86400,
		'weekly' => 604800,
	];

	/**
	 * Watches run in one pass, at most.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 25;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param FeedWatchReader $feeds The RSS and Atom reader.
	 * @param SitemapWatchReader $sitemaps The sitemap reader.
	 * @param PageWatchReader $pages The page-fragment reader.
	 * @param FediverseWatchReader $fediverse The Mastodon and Bluesky reader.
	 * @param SearchWatchReader $searches The hermiq web-search reader.
	 * @param RelevanceScorer $scorer Relevance, or nothing.
	 * @param WatchEventStore $events The idempotent event writer.
	 * @param ITimeFactory $time Time factory for the due calculation.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function __construct(
		private ListObjectStore $store,
		private FeedWatchReader $feeds,
		private SitemapWatchReader $sitemaps,
		private PageWatchReader $pages,
		private FediverseWatchReader $fediverse,
		private SearchWatchReader $searches,
		private RelevanceScorer $scorer,
		private WatchEventStore $events,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every watch.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The watches.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function watches(array $filters = []): array {
		return $this->store->findAll(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			filters: $filters
		);
	}//end watches()

	/**
	 * Every competitor.
	 *
	 * @return array<int, array<string, mixed>> The competitors.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function competitors(): array {
		return $this->store->findAll(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitor_schema', default: self::COMPETITOR_SCHEMA)
		);
	}//end competitors()

	/**
	 * One watch by id.
	 *
	 * @param string $id The watch id.
	 *
	 * @return array<string, mixed>|null The watch, or null.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function watch(string $id): ?array {
		return $this->store->find(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			id: trim($id)
		);
	}//end watch()

	/**
	 * Whether a watch is due, given when it last ran.
	 *
	 * Public because this replaces what a background job's interval would
	 * otherwise decide, and it is the one piece of scheduling logic this app
	 * still owns after ADR-094 handed the firing to the flow engine.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param int $now The current time, as a Unix timestamp.
	 *
	 * @return bool True when it should run on this firing.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function isDue(array $watch, int $now): bool {
		if (($watch['active'] ?? true) === false) {
			return false;
		}

		$lastRun = trim((string)($watch['lastRunAt'] ?? ''));
		if ($lastRun === '') {
			return true;
		}

		$stamp = strtotime($lastRun);
		if ($stamp === false) {
			return true;
		}

		$cadence = (self::CADENCE_SECONDS[(string)($watch['schedule'] ?? 'daily')] ?? self::CADENCE_SECONDS['daily']);

		return (($now - $stamp) >= $cadence);
	}//end isDue()

	/**
	 * The watches that are due right now.
	 *
	 * @param int $limit How many at most.
	 *
	 * @return array<int, array<string, mixed>> The due watches.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function dueNow(int $limit = self::DEFAULT_LIMIT): array {
		$now = $this->time->getTime();
		$out = [];
		foreach ($this->watches() as $watch) {
			if ($this->isDue(watch: $watch, now: $now) === false) {
				continue;
			}

			$out[] = $watch;
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}//end dueNow()

	/**
	 * Run every due watch, one failure at a time.
	 *
	 * @param int $limit How many watches at most.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return array{watches: int, events: int, failures: array<string, string>}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function runDue(int $limit = self::DEFAULT_LIMIT, ?string $actingUserId = null): array {
		$summary = ['watches' => 0, 'events' => 0, 'failures' => []];
		foreach ($this->dueNow(limit: $limit) as $watch) {
			$id = $this->store->idOf(payload: $watch);
			try {
				$result = $this->runOne(watch: $watch, actingUserId: $actingUserId);
			} catch (Throwable $e) {
				$this->logger->warning(
					'CompetitorWatchService: a watch threw',
					['watchId' => $id, 'exception' => $e->getMessage()]
				);
				$summary['failures'][$id] = $e->getMessage();
				continue;
			}

			$summary['watches']++;
			$summary['events'] += $result['events'];
			if ($result['outcome'] !== WatchOutcome::OK) {
				$summary['failures'][$id] = $result['reason'];
			}
		}

		return $summary;
	}//end runDue()

	/**
	 * Run one watch and record what it saw.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return array{outcome: string, reason: string, events: int}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function runOne(array $watch, ?string $actingUserId = null): array {
		$outcome = $this->readOne(watch: $watch, actingUserId: $actingUserId);
		$created = 0;
		foreach ($outcome->items as $item) {
			$stored = $this->events->upsert(
				watch: $watch,
				item: $item,
				extra: $this->scorer->fieldsFor(item: $item, actingUserId: $actingUserId)
			);
			if ($stored['created'] === true) {
				$created++;
			}
		}

		$this->recordRun(watch: $watch, outcome: $outcome);

		return ['outcome' => $outcome->outcome, 'reason' => $outcome->reason, 'events' => $created];
	}//end runOne()

	/**
	 * Dispatch one watch to its reader.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return WatchOutcome
	 */
	private function readOne(array $watch, ?string $actingUserId): WatchOutcome {
		$target = trim((string)($watch['target'] ?? ''));
		$sourceId = trim((string)($watch['connectorSourceId'] ?? ''));

		return match ((string)($watch['kind'] ?? '')) {
			'rss' => $this->feeds->read(url: $target, sourceId: $sourceId),
			'sitemap' => $this->sitemaps->read(
				url: $target,
				previous: $this->seenLocations(watch: $watch),
				sourceId: $sourceId
			),
			'page' => $this->pages->read(
				url: $target,
				selector: trim((string)($watch['selector'] ?? '')),
				fingerprint: trim((string)($watch['fingerprint'] ?? '')),
				lineFingerprints: array_map('strval', (array)($watch['lineFingerprints'] ?? [])),
				sourceId: $sourceId
			),
			'fediverse' => $this->fediverse->read(handle: $target, sourceId: $sourceId),
			'search' => $this->searches->read(query: $target, actingUserId: $actingUserId),
			default => WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'This watch has no kind that can be read.'
			),
		};
	}//end readOne()

	/**
	 * The sitemap locations this watch already recorded, rebuilt from its own
	 * events rather than from a blob on the watch, so a competitor with ten
	 * thousand pages does not turn one object into a store of its own.
	 *
	 * @param array<string, mixed> $watch The watch.
	 *
	 * @return array<string, string> Location to stamp.
	 */
	private function seenLocations(array $watch): array {
		$watchId = $this->store->idOf(payload: $watch);
		if ($watchId === '') {
			return [];
		}

		$out = [];
		foreach ($this->events->all(filters: ['watchId' => $watchId]) as $event) {
			$url = trim((string)($event['url'] ?? ''));
			if ($url !== '') {
				$out[$url] = trim((string)($event['sourceStamp'] ?? ''));
			}
		}

		return $out;
	}//end seenLocations()

	/**
	 * Record what this run did on the watch itself, so the page can say when
	 * it last ran and how it went.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param WatchOutcome $outcome What happened.
	 *
	 * @return void
	 */
	private function recordRun(array $watch, WatchOutcome $outcome): void {
		$id = $this->store->idOf(payload: $watch);
		if ($id === '') {
			return;
		}

		// MERGED ONTO THE STORED WATCH, never written as a partial payload.
		// `saveObject()` with a uuid REPLACES the object's data, so a payload
		// carrying only the run stamps would silently drop the target, the
		// selector and the schedule of the watch it was recording.
		$record = $watch;
		unset($record['@self'], $record['id'], $record['uuid']);
		$record['lastRunAt'] = gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
		$record['lastOutcome'] = $outcome->outcome;
		$record['lastReason'] = mb_substr($outcome->reason, 0, 500, 'UTF-8');
		foreach (['fingerprint', 'lineFingerprints'] as $key) {
			if (array_key_exists($key, $outcome->state) === true) {
				$record[$key] = $outcome->state[$key];
			}
		}

		$this->store->save(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			payload: $record,
			id: $id
		);
	}//end recordRun()
}//end class
