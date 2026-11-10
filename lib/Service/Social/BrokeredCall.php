<?php

/**
 * Pipelinq BrokeredCall.
 *
 * The network call a brokered request made, as its log lines name it: the
 * HTTP method and the provider-relative path.
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
 * One brokered network call; built by SocialBrokerGateway.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
final class BrokeredCall {
	/**
	 * Constructor.
	 *
	 * @param string $method The HTTP method that was attempted.
	 * @param string $path   The provider-relative path that was attempted.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
