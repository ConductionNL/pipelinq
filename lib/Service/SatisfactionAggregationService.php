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
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregate one client's survey responses into a panel.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
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
	 */
	public function npsOf(array $responses): ?float {
		$promoters = 0;
		$detractors = 0;
		$answered = 0;

		foreach ($responses as $response) {
			$score = ($response['npsScore'] ?? null);
			if (is_numeric($score) === false) {
				continue;
			}

			$answered++;
			$score = (int)$score;
			if ($score >= DetractorFollowUpService::NPS_PROMOTER_FLOOR) {
				$promoters++;
			} elseif ($score <= DetractorFollowUpService::NPS_DETRACTOR_CEILING) {
				$detractors++;
			}
		}

		if ($answered === 0) {
			// Null, not 0: a client nobody scored has no NPS, and zero is a
			// real score that means promoters and detractors cancelled out.
			return null;
		}

		return round(((($promoters - $detractors) / $answered) * 100), 1);
	}//end npsOf()

	/**
	 * The panel for one client.
	 *
	 * @param string $clientId The client's uuid.
	 * @param DateTimeImmutable|null $now The moment the windows are measured from.
	 *
	 * @return array<string, mixed> The panel, with `empty` true when there is
	 *   nothing to show.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function summarise(array $responses, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());

		if ($responses === []) {
			return [
				'empty' => true,
				'responseCount' => 0,
				'nps' => null,
				'averageRating' => null,
				'trend' => 'unknown',
				'verbatims' => [],
			];
		}

		$current = [];
		$previous = [];
		$currentFloor = $now->modify('-' . self::WINDOW_DAYS . ' days');
		$previousFloor = $now->modify('-' . (self::WINDOW_DAYS * 2) . ' days');

		foreach ($responses as $response) {
			$submitted = $this->submittedAt(response: $response);
			if ($submitted === null) {
				continue;
			}

			if ($submitted >= $currentFloor) {
				$current[] = $response;
				continue;
			}

			if ($submitted >= $previousFloor) {
				$previous[] = $response;
			}
		}

		$ratings = [];
		foreach ($responses as $response) {
			$rating = ($response['averageRating'] ?? null);
			if (is_numeric($rating) === true) {
				$ratings[] = (float)$rating;
			}
		}

		// Null, not 0: nobody rated is not the same as everybody rated zero.
		$averageRating = null;
		if ($ratings !== []) {
			$averageRating = round((array_sum($ratings) / count($ratings)), 2);
		}

		return [
			'empty' => false,
			'responseCount' => count($responses),
			'nps' => $this->npsOf(responses: $responses),
			'averageRating' => $averageRating,
			'trend' => $this->trend(current: $current, previous: $previous),
			'verbatims' => $this->verbatims(responses: $responses),
		];
	}//end summarise()

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
		$withText = array_values(
			array_filter(
				$responses,
				static fn (array $response): bool => trim((string)($response['verbatim'] ?? '')) !== ''
			)
		);

		usort(
			$withText,
			static fn (array $a, array $b): int => strcmp(
				(string)($b['submittedAt'] ?? ''),
				(string)($a['submittedAt'] ?? '')
			)
		);

		$verbatims = [];
		foreach (array_slice($withText, 0, self::VERBATIM_COUNT) as $response) {
			$verbatims[] = [
				'text' => (string)$response['verbatim'],
				'submittedAt' => (string)($response['submittedAt'] ?? ''),
				'classification' => (string)($response['classification'] ?? ''),
			];
		}

		return $verbatims;
	}//end verbatims()

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
		$clientId = trim($clientId);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($clientId === '' || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'clientRef' => $clientId],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SatisfactionAggregationService: the response read failed',
				['client' => $clientId, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}//end responsesFor()
}//end class
