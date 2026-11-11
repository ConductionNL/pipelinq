<?php

/**
 * Pipelinq ExpiryWindow
 *
 * The expiry and notice cutoffs of one programme in one run.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;

/**
 * A programme's inactivity expiry for one run: whether it applies, and its cutoffs.
 *
 * Activity before the cutoff expires; activity before the notice cutoff gets a warning.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */
final class ExpiryWindow {

	/**
	 * Whether the programme expires balances on inactivity at all.
	 *
	 * @var boolean
	 */
	public readonly bool $applies;

	/**
	 * Cutoff for expiry.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $cutoff;

	/**
	 * Cutoff for advance notice.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $noticeCutoff;

	/**
	 * Advance notice in days.
	 *
	 * @var integer
	 */
	public readonly int $notice;

	/**
	 * Constructor.
	 *
	 * The policy applies only when it is an array of type `inactivityMonths` with
	 * months above zero; otherwise both cutoffs are the run's moment and nothing expires.
	 *
	 * @param array<string, mixed> $programme The programme.
	 * @param DateTimeImmutable    $now       The run's moment.
	 */
	public function __construct(array $programme, DateTimeImmutable $now) {
		$policy = $programme['expiryPolicy'] ?? [];
		if (is_array($policy) === false) {
			$policy = ['type' => 'none'];
		}

		$type = (string)($policy['type'] ?? 'none');
		$months = (int)($policy['value'] ?? 0);
		$notice = (int)($policy['advanceNoticeDays'] ?? 30);
		$this->applies = ($type === 'inactivityMonths' && $months > 0);
		$this->notice = $notice;
		if ($this->applies === false) {
			$this->cutoff = $now;
			$this->noticeCutoff = $now;
			return;
		}

		$this->cutoff = $now->modify("-{$months} months");
		$this->noticeCutoff = $now->modify('-' . max(0, $months - (int)ceil($notice / 30)) . ' months');
	}//end __construct()
}//end class
