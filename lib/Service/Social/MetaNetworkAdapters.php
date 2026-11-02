<?php

/**
 * Pipelinq MetaNetworkAdapters.
 *
 * The adapters for the networks Meta's Graph API serves.
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
 * The Facebook page, Instagram business and Threads adapters.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class MetaNetworkAdapters {
	/**
	 * Constructor.
	 *
	 * @param FacebookPageAdapter      $facebook  A Facebook page.
	 * @param InstagramBusinessAdapter $instagram An Instagram business account.
	 * @param ThreadsAdapter           $threads   Threads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FacebookPageAdapter $facebook,
		private readonly InstagramBusinessAdapter $instagram,
		private readonly ThreadsAdapter $threads,
	) {
	}//end __construct()

	/**
	 * The adapters, in network order.
	 *
	 * @return array<int, SocialNetworkAdapter>
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function all(): array {
		return [$this->facebook, $this->instagram, $this->threads];
	}//end all()
}//end class
