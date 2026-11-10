<?php

/**
 * Pipelinq CertificateHealth
 *
 * The BRP client certificate's expiry with the whole days left at one check.
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
 * Expiry and days left; `critical` at 7 days or fewer, `warning` at 30 or fewer, else `ok`.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010-03
 */
final class CertificateHealth {

	/**
	 * Warn threshold in days.
	 *
	 * @var int
	 */
	private const WARN_THRESHOLD_DAYS = 30;

	/**
	 * Critical threshold in days.
	 *
	 * @var int
	 */
	private const CRITICAL_THRESHOLD_DAYS = 7;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $expiry   The certificate's expiry.
	 * @param int               $daysLeft Whole days left, rounded down.
	 */
	public function __construct(
		public readonly DateTimeImmutable $expiry,
		public readonly int $daysLeft,
	) {
	}//end __construct()

	/**
	 * The health of a certificate expiring at the given moment, checked now.
	 *
	 * @param DateTimeImmutable $expiry The certificate's expiry.
	 * @param DateTimeImmutable $now    The moment of the check.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010-03
	 */
	public static function at(DateTimeImmutable $expiry, DateTimeImmutable $now): self {
		return new self(expiry: $expiry, daysLeft: (int)floor(($expiry->getTimestamp() - $now->getTimestamp()) / 86400));
	}//end at()

	/**
	 * The status for the admin tile and notifications.
	 *
	 * @return string `ok`, `warning` or `critical`.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010-03
	 */
	public function status(): string {
		$status = 'ok';
		if ($this->daysLeft <= self::CRITICAL_THRESHOLD_DAYS) {
			$status = 'critical';
		} elseif ($this->daysLeft <= self::WARN_THRESHOLD_DAYS) {
			$status = 'warning';
		}

		return $status;
	}//end status()
}//end class
