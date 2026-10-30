<?php

/**
 * Pipelinq ClientRiskScore.
 *
 * The churn risk arithmetic: from a client's facts and the weights to a
 * level, a score and the reasons behind it. Pure: it reads and writes
 * nothing, so every signal, cap and boundary is testable on its own.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Churn risk score, level and reasons from facts.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskScore {

	/**
	 * Score a client from its facts. Pure: reads nothing, writes nothing.
	 *
	 * Facts: `contracts` (each with id, status, endDate and its `@self.updated`),
	 * `detractors` (each with id and submittedAt), `openComplaints`,
	 * `slaBreached`, `lastActivityAt` and `createdAt`.
	 *
	 * @param array<string, mixed> $facts The client's facts.
	 * @param array<string, int> $weights The weights (ClientRiskService::weights()).
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return array{level: string, score: int, reasons: list<array<string, mixed>>, computedAt: string}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function evaluate(array $facts, array $weights, DateTimeImmutable $now): array {
		$reasons = array_merge(
			$this->contractReasons(contracts: (array)($facts['contracts'] ?? []), weights: $weights, now: $now),
			$this->detractorReasons(detractors: (array)($facts['detractors'] ?? []), weights: $weights, now: $now),
			$this->countReasons(facts: $facts, weights: $weights),
			$this->silenceReasons(facts: $facts, weights: $weights, now: $now)
		);

		$score = 0;
		foreach ($reasons as $reason) {
			$score += (int)$reason['points'];
		}

		$level = 'low';
		if ($score >= $weights['high']) {
			$level = 'high';
		} elseif ($score >= $weights['medium']) {
			$level = 'medium';
		}

		return [
			'level' => $level,
			'score' => $score,
			'reasons' => $reasons,
			'computedAt' => $now->format(DATE_ATOM),
		];
	}//end evaluate()

	/**
	 * Whether a new assessment differs from the stored one in what a reader
	 * sees: the level, or which reasons there are. Score and time alone do not
	 * count, so a quiet client is not rewritten every night.
	 *
	 * @param mixed $stored The client's current `churnRisk`, if any.
	 * @param array<string, mixed> $assessed The new assessment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
	 */
	public function changed(mixed $stored, array $assessed): bool {
		if (is_array($stored) === false || isset($stored['level']) === false) {
			return true;
		}

		if ((string)$stored['level'] !== (string)$assessed['level']) {
			return true;
		}

		return $this->reasonKeys(reasons: (array)($stored['reasons'] ?? [])) !== $this->reasonKeys(reasons: (array)$assessed['reasons']);
	}//end changed()

	/**
	 * Reasons from the client's contracts: one per expiring contract, one per
	 * contract churned inside the window.
	 *
	 * @param array<int, mixed> $contracts The contracts.
	 * @param array<string, int> $weights The weights.
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function contractReasons(array $contracts, array $weights, DateTimeImmutable $now): array {
		$reasons = [];
		$churnedSince = $now->modify('-' . $weights['contractChurnedDays'] . ' days');
		foreach ($contracts as $contract) {
			$status = (string)($contract['status'] ?? '');
			$objectId = (string)($contract['id'] ?? ($contract['uuid'] ?? ''));
			if ($status === 'expiring') {
				$reasons[] = $this->reason(
					signal: 'contract-expiring',
					points: $weights['contractExpiring'],
					objectType: 'salesContract',
					objectId: $objectId,
					since: (string)($contract['endDate'] ?? '')
				);
				continue;
			}

			if ($status !== 'churned') {
				continue;
			}

			$when = $this->parse(raw: ($contract['endDate'] ?? null)) ?? $this->parse(raw: ($contract['@self']['updated'] ?? null));
			if ($when !== null && $when >= $churnedSince) {
				$reasons[] = $this->reason(
					signal: 'contract-churned',
					points: $weights['contractChurned'],
					objectType: 'salesContract',
					objectId: $objectId,
					since: $when->format('Y-m-d')
				);
			}
		}

		return $reasons;
	}//end contractReasons()

	/**
	 * Reasons from detractor answers inside the window, up to the cap.
	 *
	 * @param array<int, mixed> $detractors The detractor survey responses.
	 * @param array<string, int> $weights The weights.
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function detractorReasons(array $detractors, array $weights, DateTimeImmutable $now): array {
		$since = $now->modify('-' . $weights['detractorDays'] . ' days');
		$recent = [];
		foreach ($detractors as $response) {
			$when = $this->parse(raw: ($response['submittedAt'] ?? null));
			if ($when !== null && $when >= $since) {
				$recent[] = ['id' => (string)($response['id'] ?? ($response['uuid'] ?? '')), 'when' => $when];
			}
		}

		usort($recent, static fn (array $left, array $right): int => $right['when'] <=> $left['when']);

		$reasons = [];
		$budget = $weights['detractorCap'];
		foreach ($recent as $response) {
			$points = min($weights['detractor'], $budget);
			if ($points <= 0) {
				break;
			}

			$budget -= $points;
			$reasons[] = $this->reason(
				signal: 'detractor',
				points: $points,
				objectType: 'surveyResponse',
				objectId: $response['id'],
				since: $response['when']->format('Y-m-d')
			);
		}

		return $reasons;
	}//end detractorReasons()

	/**
	 * Reasons from counts: open complaints (capped) and breached deadlines.
	 *
	 * @param array<string, mixed> $facts The facts.
	 * @param array<string, int> $weights The weights.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function countReasons(array $facts, array $weights): array {
		$reasons = [];
		$complaints = max(0, (int)($facts['openComplaints'] ?? 0));
		if ($complaints > 0) {
			$reason = $this->reason(signal: 'open-complaints', points: min($complaints * $weights['complaint'], $weights['complaintCap']));
			$reason['count'] = $complaints;
			$reasons[] = $reason;
		}

		$breached = max(0, (int)($facts['slaBreached'] ?? 0));
		if ($breached > 0) {
			$reason = $this->reason(signal: 'sla-breached', points: $weights['slaBreached']);
			$reason['count'] = $breached;
			$reasons[] = $reason;
		}

		return $reasons;
	}//end countReasons()

	/**
	 * The silence reason: no activity for longer than the window. A client
	 * with no activity at all is measured from when it was created, so a
	 * client added yesterday is not silent.
	 *
	 * @param array<string, mixed> $facts The facts.
	 * @param array<string, int> $weights The weights.
	 * @param DateTimeImmutable $now The evaluation instant.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function silenceReasons(array $facts, array $weights, DateTimeImmutable $now): array {
		$last = $this->parse(raw: ($facts['lastActivityAt'] ?? null)) ?? $this->parse(raw: ($facts['createdAt'] ?? null));
		if ($last === null || $last >= $now->modify('-' . $weights['silenceDays'] . ' days')) {
			return [];
		}

		return [$this->reason(signal: 'silence', points: $weights['silence'], since: $last->format('Y-m-d'))];
	}//end silenceReasons()

	/**
	 * One reason.
	 *
	 * @param string $signal The signal code.
	 * @param int $points The points it adds.
	 * @param string $objectType The record type behind it, if any.
	 * @param string $objectId The record behind it, if any.
	 * @param string $since The date it refers to, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function reason(string $signal, int $points, string $objectType='', string $objectId='', string $since=''): array {
		$reason = ['signal' => $signal, 'points' => $points];
		if ($objectId !== '') {
			$reason['objectType'] = $objectType;
			$reason['objectId'] = $objectId;
		}

		if ($since !== '') {
			$reason['since'] = $since;
		}

		return $reason;
	}//end reason()

	/**
	 * The comparable identity of a reason list: signal and record per reason.
	 *
	 * @param array<int, mixed> $reasons The reasons.
	 *
	 * @return list<string>
	 */
	private function reasonKeys(array $reasons): array {
		$keys = [];
		foreach ($reasons as $reason) {
			if (is_array($reason) === true) {
				$keys[] = (string)($reason['signal'] ?? '') . '|' . (string)($reason['objectId'] ?? '') . '|' . (string)($reason['count'] ?? '');
			}
		}

		sort($keys);
		return $keys;
	}//end reasonKeys()

	/**
	 * Parse a date or date-time, or null.
	 *
	 * @param mixed $raw The raw value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parse(mixed $raw): ?DateTimeImmutable {
		if (is_string($raw) === false || trim($raw) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($raw);
		} catch (Throwable) {
			return null;
		}
	}//end parse()
}//end class
