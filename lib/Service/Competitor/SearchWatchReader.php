<?php

/**
 * Pipelinq SearchWatchReader.
 *
 * A saved web search, run on a schedule. It is the catch-all watch: a
 * competitor who publishes through somebody else's press release, a tender
 * award, a conference programme. None of that is on their own site, so no feed
 * or sitemap would ever see it.
 *
 * The search itself is hermiq's. Hermiq already owns a configured, guarded
 * search backend (`WebSearchClient`, behind `WebResearchEgressGuard`), and
 * building a second one here would mean a second admin-configured endpoint and
 * a second place a search key could be pasted. Hermiq is resolved by class
 * name at run time, so pipelinq keeps no compile-time dependency on it.
 *
 * WITHOUT HERMIQ THIS IS A QUIET NO-OP. It reports `not_configured` and the
 * rest of the watch run continues. A search watch is the one kind that depends
 * on another app being installed, and letting that failure stop the feed and
 * sitemap watches of the same run would make one optional dependency break
 * four working ones.
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

use OCA\Pipelinq\Service\Egress\EgressResult;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A scheduled web search through hermiq.
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
class SearchWatchReader {

	/**
	 * Hermiq's web search client, resolved by name so pipelinq carries no
	 * compile-time dependency on hermiq.
	 *
	 * @var string
	 */
	public const CLIENT_CLASS = 'OCA\\Hermiq\\Service\\WebResearch\\WebSearchClient';

	/**
	 * Results kept from one search, at most.
	 *
	 * @var int
	 */
	public const MAX_RESULTS = 10;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy hermiq resolve.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one saved search.
	 *
	 * @param string $query The saved query.
	 * @param string|null $actingUserId The identity the run acts as, for hermiq's own guard.
	 *
	 * @return WatchOutcome The results, or the reason there are none.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-five-watch-kinds-and-the-two-that-are-excluded-are-named
	 */
	public function read(string $query, ?string $actingUserId = null): WatchOutcome {
		$trimmed = trim($query);
		if ($trimmed === '') {
			return WatchOutcome::failed(outcome: EgressResult::NOT_CONFIGURED, reason: 'This watch has no search query.');
		}

		$client = $this->client();
		if ($client === null) {
			return WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'Hermiq is not available, so search watches do not run on this instance.'
			);
		}

		try {
			$answer = $client->search(query: $trimmed, actingUserId: $actingUserId);
		} catch (Throwable $e) {
			$this->logger->info('SearchWatchReader: the search failed', ['exception' => $e->getMessage()]);
			return WatchOutcome::failed(outcome: EgressResult::UNAVAILABLE, reason: 'The web search failed.');
		}

		if (is_array($answer) === false) {
			return WatchOutcome::failed(outcome: EgressResult::UNPARSABLE, reason: 'The web search answered nothing usable.');
		}

		if (is_array($answer['error'] ?? null) === true) {
			return WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: (string)($answer['error']['message'] ?? 'The web search is not configured.')
			);
		}

		return WatchOutcome::seen(items: $this->normalise(results: (array)($answer['results'] ?? [])));
	}//end read()

	/**
	 * Normalise hermiq's results into watch items.
	 *
	 * @param array<int|string, mixed> $results The results.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise(array $results): array {
		$out = [];
		foreach ($results as $result) {
			if (is_array($result) === false) {
				continue;
			}

			$url = trim((string)($result['url'] ?? ''));
			if ($url === '') {
				continue;
			}

			$out[] = [
				'url' => $url,
				'title' => trim((string)($result['title'] ?? $url)),
				'summary' => trim((string)($result['snippet'] ?? '')),
				'stamp' => $url,
			];
			if (count($out) >= self::MAX_RESULTS) {
				break;
			}
		}

		return $out;
	}//end normalise()

	/**
	 * Hermiq's search client, or null when hermiq is absent.
	 *
	 * @return object|null
	 */
	private function client(): ?object {
		try {
			$client = $this->container->get(self::CLIENT_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (method_exists($client, 'search') === false) {
			return null;
		}

		return $client;
	}//end client()
}//end class
