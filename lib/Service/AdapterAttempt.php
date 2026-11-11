<?php

/**
 * Pipelinq AdapterAttempt.
 *
 * One publish attempt through a network adapter: the adapter and the resolved
 * request it is handed.
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

use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;

/**
 * A request on its way through an adapter.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
final class AdapterAttempt {
	/**
	 * Constructor.
	 *
	 * @param SocialNetworkAdapter $adapter The network adapter.
	 * @param SocialPublishRequest $request The resolved request.
	 */
	public function __construct(
		public readonly SocialNetworkAdapter $adapter,
		public readonly SocialPublishRequest $request,
	) {
	}//end __construct()
}//end class
