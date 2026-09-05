<?php

/**
 * Pipelinq SocialGatewayResult.
 *
 * The answer to one call at a social network: either what came back, or which
 * of six things went wrong.
 *
 * The precedent for this seam is `BrokerHttpTransport`, which answers `status
 * 0` on every failure. That is enough for a payment adapter, which only needs
 * to know that it must not treat the call as accepted. It is not enough here.
 * A publish has to tell "no developer application has been filed" from "the
 * grant is gone" from "the network said no", because each of those offers the
 * marketer a different next step and only two of them can be helped by a
 * retry. A single falsy status collapses all six into one, which is how a
 * failure becomes a silent no-op.
 *
 * So the failure side is a CLOSED set. `socialPublication.failureCode` stores
 * exactly these values, the interface branches on them, and a new kind of
 * failure has to be named here before it can be recorded.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * The outcome of one brokered call to a social network.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialGatewayResult {
	/**
	 * No developer application is filed for this network, or the account
	 * carries no credential. Nothing to retry.
	 *
	 * @var string
	 */
	public const NOT_CONFIGURED = 'not_configured';

	/**
	 * The grant behind the credential is gone. Only a person re-authorising
	 * can fix it, so a retry is pointless and is not offered.
	 *
	 * @var string
	 */
	public const RELINK_NEEDED = 'relink_needed';

	/**
	 * The tenant's spend budget for this network is reached. The call was
	 * never made.
	 *
	 * @var string
	 */
	public const BUDGET_EXHAUSTED = 'budget_exhausted';

	/**
	 * The broker refused: the caller does not own the credential, the app is
	 * not granted, or the path is not in the provider's allow-rules.
	 *
	 * @var string
	 */
	public const NOT_PERMITTED = 'not_permitted';

	/**
	 * The network itself said no (a 4xx). The post can be fixed and retried.
	 *
	 * @var string
	 */
	public const REJECTED_BY_NETWORK = 'rejected_by_network';

	/**
	 * A 5xx, a transport failure, or no broker on this instance. Retryable.
	 *
	 * @var string
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Every failure code, in the order the schema enum declares them.
	 *
	 * @var array<int, string>
	 */
	public const CODES = [
		self::NOT_CONFIGURED,
		self::RELINK_NEEDED,
		self::BUDGET_EXHAUSTED,
		self::NOT_PERMITTED,
		self::REJECTED_BY_NETWORK,
		self::UNAVAILABLE,
	];

	/**
	 * The failure codes a retry can plausibly fix.
	 *
	 * @var array<int, string>
	 */
	public const RETRYABLE = [
		self::REJECTED_BY_NETWORK,
		self::UNAVAILABLE,
	];

	/**
	 * Constructor. Use {@see succeeded()} or {@see failed()} rather than this.
	 *
	 * @param bool $accepted Whether the call was accepted by the network.
	 * @param int $status The upstream HTTP status, or 0 when no call was made.
	 * @param array<string, mixed> $body The decoded response body.
	 * @param string $failureCode One of the codes above, or an empty string.
	 * @param string $failureReason A reason a marketer can act on, never carrying a secret.
	 *
	 * @return void
	 */
	private function __construct(
		public readonly bool $accepted,
		public readonly int $status,
		public readonly array $body,
		public readonly string $failureCode,
		public readonly string $failureReason,
	) {
	}//end __construct()

	/**
	 * The network accepted the call.
	 *
	 * @param int $status The upstream HTTP status.
	 * @param array<string, mixed> $body The decoded response body.
	 *
	 * @return self The success result.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public static function succeeded(int $status, array $body): self {
		return new self(accepted: true, status: $status, body: $body, failureCode: '', failureReason: '');
	}//end succeeded()

	/**
	 * The call did not succeed, for one of the six named reasons.
	 *
	 * @param string $code One of the codes on this class.
	 * @param string $reason A reason a marketer can act on.
	 * @param int $status The upstream status when there was one, else 0.
	 *
	 * @return self The failure result.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public static function failed(string $code, string $reason, int $status = 0): self {
		$known = $code;
		if (in_array($code, self::CODES, true) === false) {
			// An unnamed failure is the one thing this class exists to prevent.
			// Coercing it to `unavailable` keeps the stored enum valid; keeping
			// the original word in the reason keeps the diagnosis.
			$known = self::UNAVAILABLE;
			$reason = $reason . ' (unmapped failure: ' . $code . ')';
		}

		return new self(accepted: false, status: $status, body: [], failureCode: $known, failureReason: $reason);
	}//end failed()

	/**
	 * Whether trying this call again could plausibly succeed.
	 *
	 * @return bool True when a retry is worth offering.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function isRetryable(): bool {
		return ($this->accepted === false && in_array($this->failureCode, self::RETRYABLE, true) === true);
	}//end isRetryable()
}//end class
