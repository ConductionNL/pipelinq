<?php

/**
 * Pipelinq FediverseWatchReader.
 *
 * Reads a competitor's public timeline on Mastodon or Bluesky. These two are
 * here and the others are not for one reason, and it is worth writing down
 * rather than leaving as an apparent oversight: both publish a public timeline
 * that a reader may fetch without impersonating anybody. LinkedIn and Meta do
 * not, on any tier, and the only way to get another organisation's posts from
 * them is to scrape, which this programme has ruled out.
 *
 * No credential is used here. A public timeline is public, so the read goes
 * out through the same egress source as the feeds and needs no grant, which is
 * also why a competitor watch never touches the credential broker.
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;

/**
 * Public Mastodon and Bluesky timelines.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The named constructors of the
 *  immutable result type this class returns (WatchOutcome). A value
 *  object's own factory is not the hidden dependency StaticAccess exists
 *  to catch: there is nothing here that could be injected, and the
 *  alternative is a constructor call whose argument order says less than
 *  the method name does.
 */
class FediverseWatchReader {

	/**
	 * Bluesky's public AppView, which serves an author feed without a grant.
	 *
	 * @var string
	 */
	public const BLUESKY_APPVIEW = 'https://public.api.bsky.app';

	/**
	 * Posts read from one timeline, at most.
	 *
	 * @var int
	 */
	public const MAX_POSTS = 25;

	/**
	 * Constructor.
	 *
	 * @param ConnectorEgress $egress The single outbound seam.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function __construct(
		private ConnectorEgress $egress,
	) {
	}//end __construct()

	/**
	 * Read a public timeline.
	 *
	 * @param string $handle The handle, as `user@instance` for Mastodon or a domain handle for Bluesky.
	 * @param string $sourceId The OpenConnector source to read it through.
	 *
	 * @return WatchOutcome The posts, or the reason there are none.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function read(string $handle, string $sourceId = ''): WatchOutcome {
		$trimmed = ltrim(trim($handle), '@');
		if ($trimmed === '') {
			return WatchOutcome::failed(outcome: EgressResult::NOT_CONFIGURED, reason: 'This watch has no handle.');
		}

		if (str_contains($trimmed, '@') === true) {
			return $this->mastodon(handle: $trimmed, sourceId: $sourceId);
		}

		return $this->bluesky(handle: $trimmed, sourceId: $sourceId);
	}//end read()

	/**
	 * A Mastodon account's public statuses.
	 *
	 * @param string $handle `user@instance`.
	 * @param string $sourceId The source to read through.
	 *
	 * @return WatchOutcome
	 */
	private function mastodon(string $handle, string $sourceId): WatchOutcome {
		[$user, $instance] = explode('@', $handle, 2);
		$base = ('https://' . $instance);
		$lookup = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/lookup'),
			config: ['query' => ['acct' => ($user . '@' . $instance)]],
			sourceId: $sourceId
		);
		if ($lookup->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$lookup->failure, reason: $lookup->reason);
		}

		$account = $lookup->json();
		$accountId = trim((string)($account['id'] ?? ''));
		if ($accountId === '') {
			return WatchOutcome::failed(
				outcome: EgressResult::UNPARSABLE,
				reason: 'The account ' . $handle . ' was not found on ' . $instance . '.'
			);
		}

		$statuses = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/' . rawurlencode($accountId) . '/statuses'),
			config: ['query' => ['limit' => (string)self::MAX_POSTS, 'exclude_replies' => 'true']],
			sourceId: $sourceId
		);
		if ($statuses->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$statuses->failure, reason: $statuses->reason);
		}

		$rows = $statuses->json();
		if ($rows === null) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The timeline of ' . $handle . ' could not be read.');
		}

		return WatchOutcome::seen(items: $this->fromMastodon(rows: $rows, handle: $handle));
	}//end mastodon()

	/**
	 * A Bluesky account's public author feed.
	 *
	 * @param string $handle The domain handle.
	 * @param string $sourceId The source to read through.
	 *
	 * @return WatchOutcome
	 */
	private function bluesky(string $handle, string $sourceId): WatchOutcome {
		$feed = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: (self::BLUESKY_APPVIEW . '/xrpc/app.bsky.feed.getAuthorFeed'),
			config: ['query' => ['actor' => $handle, 'limit' => (string)self::MAX_POSTS]],
			sourceId: $sourceId
		);
		if ($feed->succeeded === false) {
			return WatchOutcome::failed(outcome: (string)$feed->failure, reason: $feed->reason);
		}

		$decoded = $feed->json();
		if ($decoded === null || is_array($decoded['feed'] ?? null) === false) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The feed of ' . $handle . ' could not be read.');
		}

		return WatchOutcome::seen(items: $this->fromBluesky(rows: (array)$decoded['feed'], handle: $handle));
	}//end bluesky()

	/**
	 * Normalise Mastodon statuses.
	 *
	 * @param array<int|string, mixed> $rows The statuses.
	 * @param string $handle The handle, for the title.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fromMastodon(array $rows, string $handle): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$url = trim((string)($row['url'] ?? ($row['uri'] ?? '')));
			if ($url === '') {
				continue;
			}

			$out[] = [
				'url' => $url,
				'title' => ($handle . ' posted'),
				'summary' => $this->shorten(value: (string)($row['content'] ?? '')),
				'stamp' => trim((string)($row['id'] ?? $url)),
				'publishedAt' => trim((string)($row['created_at'] ?? '')),
			];
		}

		return $out;
	}//end fromMastodon()

	/**
	 * Normalise a Bluesky author feed.
	 *
	 * @param array<int|string, mixed> $rows The feed entries.
	 * @param string $handle The handle, for the title.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fromBluesky(array $rows, string $handle): array {
		$out = [];
		foreach ($rows as $row) {
			$post = null;
			if (is_array($row) === true) {
				$post = ($row['post'] ?? null);
			}

			if (is_array($post) === false) {
				continue;
			}

			$uri = trim((string)($post['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$record = ($post['record'] ?? []);
			$text = '';
			if (is_array($record) === true) {
				$text = (string)($record['text'] ?? '');
			}

			$out[] = [
				'url' => $this->blueskyPermalink(uri: $uri, handle: $handle),
				'title' => ($handle . ' posted'),
				'summary' => $this->shorten(value: $text),
				'stamp' => trim((string)($post['cid'] ?? $uri)),
				'publishedAt' => trim((string)($post['indexedAt'] ?? '')),
			];
		}

		return $out;
	}//end fromBluesky()

	/**
	 * A readable Bluesky permalink from an `at://` URI.
	 *
	 * @param string $uri The AT Protocol URI.
	 * @param string $handle The handle.
	 *
	 * @return string The permalink, or the URI when it cannot be shaped.
	 */
	private function blueskyPermalink(string $uri, string $handle): string {
		$parts = explode('/', $uri);
		$rkey = end($parts);
		if (is_string($rkey) === false || $rkey === '') {
			return $uri;
		}

		return ('https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($rkey));
	}//end blueskyPermalink()

	/**
	 * A plain-text summary, capped.
	 *
	 * @param string $value The post body, possibly HTML.
	 *
	 * @return string
	 */
	private function shorten(string $value): string {
		$text = trim(strip_tags($value));
		if (mb_strlen($text, 'UTF-8') <= 500) {
			return $text;
		}

		return (mb_substr($text, 0, 497, 'UTF-8') . '...');
	}//end shorten()
}//end class
