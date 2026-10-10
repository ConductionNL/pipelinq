<?php
/**
 * Pipelinq SurveyInvitationFigures.
 *
 * The response-rate figures of satisfaction invitations
 * (customer-satisfaction-closed-loop): answered out of delivered, with
 * suppressed and failed counted beside the rate, per channel, and the
 * invitations of a period. Stateless arithmetic, kept apart from the
 * dispatch engine.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Counts satisfaction invitations into response-rate figures.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
 */
class SurveyInvitationFigures {

	/**
	 * The response rate of a set of invitations.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 *
	 * @return array{delivered: int, responded: int, rate: float, suppressed: int, failed: int} The figures.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function rate(array $invitations): array {
		$counts = ['sent' => 0, 'responded' => 0, 'suppressed' => 0, 'failed' => 0];
		foreach ($invitations as $invitation) {
			$status = (string)($invitation['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		$delivered = ($counts['sent'] + $counts['responded']);
		$rate      = 0.0;
		if ($delivered !== 0) {
			$rate = round((($counts['responded'] / $delivered) * 100), 1);
		}

		return [
			'delivered' => $delivered,
			'responded' => $counts['responded'],
			'rate' => $rate,
			'suppressed' => $counts['suppressed'],
			'failed' => $counts['failed'],
		];
	}//end rate()

	/**
	 * The same figures per channel; an invitation without one counts as `unknown`.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 *
	 * @return array<string, array<string, int|float>> The figures by channel, sorted.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function byChannel(array $invitations): array {
		$groups = [];
		foreach ($invitations as $invitation) {
			$channel = trim((string)($invitation['channel'] ?? ''));
			if ($channel === '') {
				$channel = 'unknown';
			}

			$groups[$channel][] = $invitation;
		}

		ksort($groups);

		return array_map(fn (array $group): array => $this->rate(invitations: $group), $groups);
	}//end byChannel()

	/**
	 * The invitations that fell due in the last `$days` days.
	 *
	 * Every invitation carries `scheduledFor`, suppressed ones included, so
	 * the period is read from that and not from `sentAt`, which a suppressed
	 * or failed invitation never gets.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 * @param int                              $days        The period; 0 or less keeps all.
	 * @param DateTimeImmutable|null           $now         The clock, for tests.
	 *
	 * @return array<int, array<string, mixed>> The invitations inside the period.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function withinDays(array $invitations, int $days, ?DateTimeImmutable $now = null): array {
		if ($days <= 0) {
			return array_values($invitations);
		}

		$now   = ($now ?? new DateTimeImmutable());
		$since = $now->modify("-{$days} days");
		$kept  = [];
		foreach ($invitations as $invitation) {
			$dueAt = $this->dueAt(invitation: $invitation);
			if ($dueAt !== null && $dueAt >= $since && $dueAt <= $now) {
				$kept[] = $invitation;
			}
		}

		return $kept;
	}//end withinDays()

	/**
	 * When an invitation fell due, or null when it does not say.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function dueAt(array $invitation): ?DateTimeImmutable {
		$due = trim((string)($invitation['scheduledFor'] ?? ''));
		if ($due === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($due);
		} catch (Throwable) {
			return null;
		}
	}//end dueAt()
}//end class
