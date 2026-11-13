<?php

/**
 * Pipelinq SatisfactionAggregationService.
 *
 * What one client's satisfaction looks like: their NPS, their average rating,
 * how many people answered, which way it is moving, and what they actually
 * said.
 *
 * THE TREND NEEDS TWO WINDOWS OR IT IS NOT A TREND. A single number with an
 * arrow beside it is a decoration. The arrow here compares the last 90 days
 * against the 90 before them, and says `unknown` when either window is empty,
 * because "no data" and "flat" are different and only one of them is true.
 *
 * NOTHING IS STORED. Every figure is derived on read from the responses that
 * exist, so a response deleted or corrected today changes the panel today.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregate one client's survey responses into a panel.
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
 */
class SatisfactionAggregationService {
	/**
	 * The window each half of the trend covers, in days.
	 *
	 * @var int
	 */
	public const WINDOW_DAYS = 90;

	/**
	 * How many verbatims the panel shows.
	 *
	 * @var int
	 */
	public const VERBATIM_COUNT = 3;

	/**
	 * Upper bound on the responses read for one client.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * The panel when there are no responses.
	 *
	 * @var array<string, mixed>
	 */
	private const EMPTY_PANEL = [
		'empty' => true,
		'responseCount' => 0,
		'nps' => null,
		'averageRating' => null,
		'trend' => 'unknown',
		'verbatims' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The NPS of a set of responses: promoters minus detractors, as a
	 * percentage of those who answered the question.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 *
	 * @return float|null The score, or null when nobody answered the question.
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function npsOf(array $responses): ?float {
		$net = 0;
		$answered = 0;
		foreach ($responses as $response) {
			$score = ($response['npsScore'] ?? null);
			if (is_numeric($score) === true) {
				$answered++;
				$net += $this->npsBand(score: (int)$score);
			}
		}

		if ($answered === 0) {
			// Null, not 0: a client nobody scored has no NPS, and zero is a
			// real score that means promoters and detractors cancelled out.
			return null;
		}

		return round((($net / $answered) * 100), 1);
	}//end npsOf()

	/**
	 * Whether a score promotes (+1), detracts (-1) or is passive (0).
	 *
	 * @param int $score The 0-10 score.
	 *
	 * @return int
	 */
	private function npsBand(int $score): int {
		if ($score >= DetractorFollowUpService::NPS_PROMOTER_FLOOR) {
			return 1;
		}

		if ($score <= DetractorFollowUpService::NPS_DETRACTOR_CEILING) {
			return -1;
		}

		return 0;
	}//end npsBand()

	/**
	 * The panel for one client.
	 *
	 * @param string $clientId The client's uuid.
	 * @param DateTimeImmutable|null $now The moment the windows are measured from.
	 *
	 * @return array<string, mixed> The panel, with `empty` true when there is
	 *   nothing to show.
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function forClient(string $clientId, ?DateTimeImmutable $now = null): array {
		$responses = $this->responsesFor(clientId: $clientId);

		return $this->summarise(responses: $responses, now: $now);
	}//end forClient()

	/**
	 * Summarise a set of responses.
	 *
	 * Separate from the read so the whole panel is testable without a store.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 * @param DateTimeImmutable|null $now The moment the windows are measured from.
	 *
	 * @return array<string, mixed> The panel.
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function summarise(array $responses, ?DateTimeImmutable $now = null): array {
		if ($responses === []) {
			return self::EMPTY_PANEL;
		}

		[$current, $previous] = $this->windows(responses: $responses, now: ($now ?? new DateTimeImmutable()));
		return [
			'empty' => false,
			'responseCount' => count($responses),
			'nps' => $this->npsOf(responses: $responses),
			'averageRating' => $this->averageRating(responses: $responses),
			'trend' => $this->trend(current: $current, previous: $previous),
			'verbatims' => $this->verbatims(responses: $responses),
		];
	}//end summarise()

	/**
	 * Split responses into the last window and the window before it.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 * @param DateTimeImmutable $now The moment the windows are measured from.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
	 */
	private function windows(array $responses, DateTimeImmutable $now): array {
		$windows = [[], []];
		foreach ($responses as $response) {
			$window = $this->windowOf(response: $response, now: $now);
			if ($window !== null) {
				$windows[$window][] = $response;
			}
		}

		return $windows;
	}//end windows()

	/**
	 * 0 for the last window, 1 for the one before it, null when older or undated.
	 *
	 * @param array<string, mixed> $response The response.
	 * @param DateTimeImmutable $now The moment the windows are measured from.
	 *
	 * @return integer|null
	 */
	private function windowOf(array $response, DateTimeImmutable $now): ?int {
		$submitted = $this->submittedAt(response: $response);
		if ($submitted === null) {
			return null;
		}

		if ($submitted >= $now->modify('-' . self::WINDOW_DAYS . ' days')) {
			return 0;
		}

		if ($submitted >= $now->modify('-' . (self::WINDOW_DAYS * 2) . ' days')) {
			return 1;
		}

		return null;
	}//end windowOf()

	/**
	 * Mean of the numeric ratings, two decimals.
	 *
	 * Null, not 0: nobody rated is not the same as everybody rated zero.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 *
	 * @return float|null
	 */
	private function averageRating(array $responses): ?float {
		$ratings = [];
		foreach ($responses as $response) {
			$rating = ($response['averageRating'] ?? null);
			if (is_numeric($rating) === true) {
				$ratings[] = (float)$rating;
			}
		}

		if ($ratings === []) {
			return null;
		}

		return round((array_sum($ratings) / count($ratings)), 2);
	}//end averageRating()

	/**
	 * Which way the score is moving between the two windows.
	 *
	 * @param array<int, array<string, mixed>> $current The last window.
	 * @param array<int, array<string, mixed>> $previous The window before it.
	 *
	 * @return string up, down, flat or unknown.
	 */
	private function trend(array $current, array $previous): string {
		$now = $this->npsOf(responses: $current);
		$before = $this->npsOf(responses: $previous);

		if ($now === null || $before === null) {
			// A trend needs two windows. Saying "flat" here would state
			// something nobody measured.
			return 'unknown';
		}

		if ($now > $before) {
			return 'up';
		}

		if ($now < $before) {
			return 'down';
		}

		return 'flat';
	}//end trend()

	/**
	 * The most recent verbatims, newest first.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 *
	 * @return array<int, array<string, string>> The verbatims.
	 */
	private function verbatims(array $responses): array {
		$verbatims = [];
		foreach (array_slice($this->newestWithText(responses: $responses), 0, self::VERBATIM_COUNT) as $response) {
			$verbatims[] = [
				'text' => (string)$response['verbatim'],
				'submittedAt' => (string)($response['submittedAt'] ?? ''),
				'classification' => (string)($response['classification'] ?? ''),
			];
		}

		return $verbatims;
	}//end verbatims()

	/**
	 * The responses carrying a non-blank verbatim, newest first.
	 *
	 * @param array<int, array<string, mixed>> $responses The responses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function newestWithText(array $responses): array {
		$withText = array_values(
			array_filter(
				$responses,
				static fn (array $response): bool => trim((string)($response['verbatim'] ?? '')) !== ''
			)
		);
		usort(
			$withText,
			static fn (array $a, array $b): int => strcmp((string)($b['submittedAt'] ?? ''), (string)($a['submittedAt'] ?? ''))
		);

		return $withText;
	}//end newestWithText()

	/**
	 * When a response was submitted.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return DateTimeImmutable|null The instant, or null when unusable.
	 */
	private function submittedAt(array $response): ?DateTimeImmutable {
		$value = trim((string)($response['submittedAt'] ?? ''));
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end submittedAt()

	/**
	 * The responses linked to one client.
	 *
	 * @param string $clientId The client's uuid.
	 *
	 * @return array<int, array<string, mixed>> The responses.
	 */
	private function responsesFor(string $clientId): array {
		$filters = $this->clientFilters(clientId: trim($clientId));
		if ($filters === []) {
			return [];
		}

		return $this->plainRows(rows: $this->readRows(filters: $filters));
	}//end responsesFor()

	/**
	 * Run the read; a failure is logged and reads as no rows.
	 *
	 * @param array<string, string> $filters The client filter.
	 *
	 * @return array<int, mixed>
	 */
	private function readRows(array $filters): array {
		try {
			return $this->objectService->findAll(config: ['filters' => $filters, 'limit' => self::LIMIT]);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'SatisfactionAggregationService: the response read failed',
				context: ['client' => $filters['clientRef'], 'exception' => $e->getMessage()]
			);

			return [];
		}
	}//end readRows()

	/**
	 * The findAll() filter for a client's responses; [] when any part is unset.
	 *
	 * @param string $clientId The trimmed client uuid.
	 *
	 * @return array<string, string>
	 */
	private function clientFilters(string $clientId): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'surveyResponse_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return [];
		}

		return ['register' => $register, 'schema' => $schema, 'clientRef' => $clientId];
	}//end clientFilters()

	/**
	 * Keep array rows and the array form of serialisable rows; drop the rest.
	 *
	 * @param array<int, mixed> $rows The findAll() rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plainRows(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (($row instanceof \JsonSerializable) === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end plainRows()
}//end class
