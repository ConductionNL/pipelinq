<?php

/**
 * Pipelinq MeteredRead.
 *
 * One metered metrics read: the request a network documents and the adapter
 * that names its network and its read cost.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * A read and the adapter it goes through; built by SocialMetricsService.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
 */
final class MeteredRead {
	/**
	 * Constructor.
	 *
	 * @param SocialAdapterCall    $call    The request.
	 * @param SocialNetworkAdapter $adapter The adapter, for its network and read cost.
	 */
	public function __construct(
		public readonly SocialAdapterCall $call,
		public readonly SocialNetworkAdapter $adapter,
	) {
	}//end __construct()
}//end class
