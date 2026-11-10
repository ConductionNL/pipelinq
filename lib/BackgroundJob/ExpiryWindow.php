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
 * Activity before the cutoff expires; activity before the notice cutoff gets a warning.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */
final class ExpiryWindow {

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
	 * @param ExpiryPolicy      $policy The programme's policy.
	 * @param DateTimeImmutable $now    The run's moment.
	 */
	public function __construct(ExpiryPolicy $policy, DateTimeImmutable $now) {
		$months = $policy->months;
		$notice = $policy->notice;
		$this->cutoff = $now->modify("-{$months} months");
		$this->noticeCutoff = $now->modify('-' . max(0, $months - (int)ceil($notice / 30)) . ' months');
		$this->notice = $notice;
	}//end __construct()
}//end class
