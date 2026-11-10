<?php

/**
 * Pipelinq PublishingAccount.
 *
 * The account a post is published to: the id the post names and the account
 * row it resolved to, if it still exists.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * An account a post is published to.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
final class PublishingAccount {
	/**
	 * Constructor.
	 *
	 * @param string                    $id  The account id the post names.
	 * @param array<string, mixed>|null $row The account, or null when it no longer exists.
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?array $row,
	) {
	}//end __construct()

	/**
	 * The account's network, or '' when the account no longer exists.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function network(): string {
		if ($this->row === null) {
			return '';
		}

		return (string)($this->row['network'] ?? '');
	}//end network()

	/**
	 * How the account publishes: `api` (an adapter posts) or `share` (a person does).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publishMode(): string {
		return (string)($this->row['publishMode'] ?? 'api');
	}//end publishMode()
}//end class
