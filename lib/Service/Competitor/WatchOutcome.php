<?php

/**
 * Pipelinq WatchOutcome.
 *
 * What one competitor watch produced on one run: the items it saw, or the
 * reason it saw none. The two are never the same value, for the same reason
 * {@see \OCA\Pipelinq\Service\Egress\EgressResult} keeps them apart: a
 * competitor whose feed refused us must not read as a competitor who published
 * nothing.
 *
 * An item is `{url, title, summary, stamp}`. `stamp` is whatever decides that
 * the item changed, and its meaning is the watch kind's: a feed guid, a
 * sitemap `lastmod`, or a fragment fingerprint.
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

/**
 * The result of one watch run.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
 */
final class WatchOutcome {

	/**
	 * The run succeeded, whether or not it found anything.
	 *
	 * @var string
	 */
	public const OK = 'ok';

	/**
	 * Constructor.
	 *
	 * @param string $outcome `ok` or one of the egress failure codes.
	 * @param string $reason A sentence a page can render, empty on success.
	 * @param array<int, array<string, mixed>> $items What the watch saw.
	 * @param array<string, mixed> $state What to remember for the next run.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $reason = '',
		public readonly array $items = [],
		public readonly array $state = [],
	) {
	}//end __construct()

	/**
	 * A successful run.
	 *
	 * @param array<int, array<string, mixed>> $items What the watch saw.
	 * @param array<string, mixed> $state What to remember.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public static function seen(array $items, array $state = []): self {
		return new self(outcome: self::OK, items: $items, state: $state);
	}//end seen()

	/**
	 * A run that could not happen, or could not be read.
	 *
	 * @param string $outcome One of the egress failure codes.
	 * @param string $reason Why.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public static function failed(string $outcome, string $reason): self {
		return new self(outcome: $outcome, reason: $reason);
	}//end failed()

	/**
	 * Whether the run succeeded.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function succeeded(): bool {
		return ($this->outcome === self::OK);
	}//end succeeded()
}//end class
