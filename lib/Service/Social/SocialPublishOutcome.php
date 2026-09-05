<?php

/**
 * Pipelinq SocialPublishOutcome.
 *
 * What one account's publish did: the network's own id and address on the way
 * up, or a named failure and a readable reason on the way down.
 *
 * It carries a cost because one network charges for the attempt. Everything
 * else records zero, which is a number rather than an absence, so a report can
 * add the column up without knowing which network was which.
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
 * The result of publishing one post to one account.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
class SocialPublishOutcome {
	/**
	 * Constructor. Use {@see published()} or {@see refused()}.
	 *
	 * @param bool $accepted Whether the network accepted the post.
	 * @param string $externalId The network's own id for the published item.
	 * @param string $url The public address of the published item.
	 * @param float $cost What the attempt cost in euros.
	 * @param string $failureCode One of the `SocialGatewayResult` codes, or an empty string.
	 * @param string $failureReason A reason a marketer can act on.
	 *
	 * @return void
	 */
	private function __construct(
		public readonly bool $accepted,
		public readonly string $externalId,
		public readonly string $url,
		public readonly float $cost,
		public readonly string $failureCode,
		public readonly string $failureReason,
	) {
	}//end __construct()

	/**
	 * The network accepted the post.
	 *
	 * @param string $externalId The network's own id for the item.
	 * @param string $url The public address of the item, when the network gives one.
	 * @param float $cost What the post cost in euros.
	 *
	 * @return self The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public static function published(string $externalId, string $url = '', float $cost = 0.0): self {
		return new self(
			accepted: true,
			externalId: $externalId,
			url: $url,
			cost: $cost,
			failureCode: '',
			failureReason: '',
		);
	}//end published()

	/**
	 * The post did not go out, for a named reason.
	 *
	 * @param string $code One of the `SocialGatewayResult` failure codes.
	 * @param string $reason A reason a marketer can act on.
	 * @param float $cost What the refused attempt still cost, which is usually nothing.
	 *
	 * @return self The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public static function refused(string $code, string $reason, float $cost = 0.0): self {
		return new self(
			accepted: false,
			externalId: '',
			url: '',
			cost: $cost,
			failureCode: $code,
			failureReason: $reason,
		);
	}//end refused()

	/**
	 * Build a refusal straight from a gateway result, keeping its code and reason.
	 *
	 * @param SocialGatewayResult $result The gateway's failure.
	 *
	 * @return self The outcome.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public static function fromGatewayFailure(SocialGatewayResult $result): self {
		return self::refused(code: $result->failureCode, reason: $result->failureReason);
	}//end fromGatewayFailure()
}//end class
