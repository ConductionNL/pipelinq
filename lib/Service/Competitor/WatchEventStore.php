<?php

/**
 * Pipelinq WatchEventStore.
 *
 * Writes what a watch saw, upserted per (watch, URL).
 *
 * The identity rule is the Search Console importer's, for the same reason: a
 * schedule that fires twice, a manual run next to an automatic one, or an
 * overlapping retry must not turn one headline into three rows on the
 * Competitors page. `seenAt` is stamped on the FIRST sighting and left alone
 * afterwards, so "what did they publish this week" keeps meaning what it says.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Idempotent writes of watch events.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
 */
class WatchEventStore {

	/**
	 * The schema slug the register fragment declares.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'watchEvent';

	/**
	 * App-config key that may override the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'watchEvent_schema';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param ITimeFactory $time Time factory for the first-sighting stamp.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function __construct(
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The schema slug in use.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}//end schemaSlug()

	/**
	 * Every event, optionally filtered.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The events.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function all(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug(), filters: $filters);
	}//end all()

	/**
	 * Create or update the event for (watch, URL).
	 *
	 * @param array<string, mixed> $watch The watch that saw it.
	 * @param array<string, mixed> $item The item, as the reader normalised it.
	 * @param array<string, mixed> $extra Fields the scorer added, possibly none.
	 *
	 * @return array{created: bool, event: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function upsert(array $watch, array $item, array $extra = []): array {
		$watchId = $this->store->idOf(payload: $watch);
		$url = trim((string)($item['url'] ?? ''));
		if ($watchId === '' || $url === '') {
			return ['created' => false, 'event' => null];
		}

		$existing = $this->existing(watchId: $watchId, url: $url);

		// The stored event is the BASE of the update, not something the new
		// fields replace: `saveObject()` with a uuid replaces the object's
		// data, so a payload without `relevanceScore` would erase a score
		// hermiq wrote on an earlier run.
		$base = ($existing ?? []);
		unset($base['@self'], $base['id'], $base['uuid']);
		$record = [
			'competitorId' => trim((string)($watch['competitorId'] ?? '')),
			'watchId' => $watchId,
			'kind' => trim((string)($watch['kind'] ?? '')),
			'title' => mb_substr(trim((string)($item['title'] ?? $url)), 0, 500, 'UTF-8'),
			'url' => $url,
			'diffSummary' => mb_substr(trim((string)($item['summary'] ?? '')), 0, 2000, 'UTF-8'),
			'sourceStamp' => mb_substr(trim((string)($item['stamp'] ?? '')), 0, 128, 'UTF-8'),
			'seenAt' => $this->seenAt(existing: $existing),
		];

		$id = null;
		if ($existing !== null) {
			$id = $this->store->idOf(payload: $existing);
		}

		$saved = $this->store->save(
			schemaSlug: $this->schemaSlug(),
			payload: array_merge($base, $record, $extra),
			id: $id
		);

		return ['created' => ($existing === null), 'event' => $saved];
	}//end upsert()

	/**
	 * The event already holding this identity, or null.
	 *
	 * @param string $watchId The watch.
	 * @param string $url The URL.
	 *
	 * @return array<string, mixed>|null
	 */
	private function existing(string $watchId, string $url): ?array {
		$found = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['watchId' => $watchId, 'url' => $url]
		);
		foreach ($found as $event) {
			return $event;
		}

		return null;
	}//end existing()

	/**
	 * When this item was FIRST seen: the stored value when there is one, now
	 * otherwise.
	 *
	 * @param array<string, mixed>|null $existing The stored event.
	 *
	 * @return string An ISO 8601 instant.
	 */
	private function seenAt(?array $existing): string {
		$stored = trim((string)($existing['seenAt'] ?? ''));
		if ($stored !== '') {
			return $stored;
		}

		return gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
	}//end seenAt()
}//end class
