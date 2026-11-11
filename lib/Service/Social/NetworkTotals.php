<?php

/**
 * Pipelinq NetworkTotals.
 *
 * Each network's publications of a period, summed: the count, the numbers,
 * the follower count of its accounts and the interaction rate.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
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
 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * Network totals in progress; built by SocialMetricsService per period.
 *
 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
 */
final class NetworkTotals {
	/**
	 * How each network is named on screen.
	 *
	 * @var array<string, string>
	 */
	private const NETWORK_LABELS = [
		'mastodon' => 'Mastodon',
		'bluesky' => 'Bluesky',
		'linkedin' => 'LinkedIn',
		'instagram' => 'Instagram',
		'facebook' => 'Facebook',
		'threads' => 'Threads',
		'x' => 'X',
	];

	/**
	 * The rows so far, per network.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $totals = [];

	/**
	 * Constructor.
	 *
	 * @param int $since The Unix time the period starts.
	 */
	public function __construct(
		private readonly int $since,
	) {
	}//end __construct()

	/**
	 * Add one publication to its network's row, when it is published in the period.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
	 */
	public function add(array $publication): void {
		$network = (string)($publication['network'] ?? '');
		if ($network === '' || $this->publishedSince(publication: $publication) === false) {
			return;
		}

		$this->totals[$network] = $this->addPublication(row: ($this->totals[$network] ?? $this->emptyTotal(network: $network)), publication: $publication);
	}//end add()

	/**
	 * Add the follower count of each network's accounts.
	 *
	 * @param array<int, array<string, mixed>> $accounts The connected accounts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
	 */
	public function addFollowers(array $accounts): void {
		foreach ($accounts as $account) {
			$network = (string)($account['network'] ?? '');
			if (isset($this->totals[$network]) === true) {
				$this->totals[$network]['followers'] += (int)($account['followerCount'] ?? 0);
			}
		}
	}//end addFollowers()

	/**
	 * The per-network rows with their interaction rate, most publications first.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
	 */
	public function rows(): array {
		$rows = array_map(static fn (array $row): array => self::withEngagement(row: $row), array_values($this->totals));

		usort(
			$rows,
			static fn (array $left, array $right): int => ($right['published'] <=> $left['published'])
		);

		return $rows;
	}//end rows()

	/**
	 * A network row with its interaction rate (null without views).
	 *
	 * @param array<string, mixed> $row The network's totals.
	 *
	 * @return array<string, mixed>
	 */
	private static function withEngagement(array $row): array {
		$engagement = ($row['likes'] + $row['comments'] + $row['shares']);
		$row['engagementPercent'] = null;
		if ($row['views'] > 0) {
			$row['engagementPercent'] = round((100 * $engagement / $row['views']), 1);
		}

		return $row;
	}//end withEngagement()


	/**
	 * Whether a publication went out at or after a moment.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return bool True when it is published and not older than `$since`.
	 */
	private function publishedSince(array $publication): bool {
		if (($publication['status'] ?? '') !== SocialPublicationStore::PUBLISHED) {
			return false;
		}

		$publishedAt = strtotime((string)($publication['publishedAt'] ?? ''));

		return $publishedAt !== false && $publishedAt >= $this->since;
	}//end publishedSince()


	/**
	 * A network's row before any publication is added.
	 *
	 * @param string $network The network.
	 *
	 * @return array<string, mixed> The empty row.
	 */
	private function emptyTotal(string $network): array {
		return [
			'network' => $network,
			'label' => (self::NETWORK_LABELS[$network] ?? ucfirst($network)),
			'published' => 0,
			'views' => 0,
			'likes' => 0,
			'comments' => 0,
			'shares' => 0,
			'followers' => 0,
		];
	}//end emptyTotal()


	/**
	 * Add one publication's numbers to its network's row.
	 *
	 * @param array<string, mixed> $row The network's row so far.
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return array<string, mixed> The row with the publication added.
	 */
	private function addPublication(array $row, array $publication): array {
		$metrics = ($publication['metrics'] ?? []);
		if (is_array($metrics) === false) {
			$metrics = [];
		}

		$row['published']++;
		foreach (['views', 'likes', 'comments', 'shares'] as $key) {
			$row[$key] += (int)($metrics[$key] ?? 0);
		}

		return $row;
	}//end addPublication()
}//end class
