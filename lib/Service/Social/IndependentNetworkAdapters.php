<?php

/**
 * Pipelinq IndependentNetworkAdapters.
 *
 * The adapters for the networks outside Meta's Graph API.
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
 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * The Mastodon, Bluesky, LinkedIn and X adapters.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
class IndependentNetworkAdapters {
	/**
	 * Constructor.
	 *
	 * @param MastodonAdapter $mastodon Mastodon.
	 * @param BlueskyAdapter  $bluesky  Bluesky.
	 * @param LinkedInAdapter $linkedin LinkedIn, member and organisation.
	 * @param XAdapter        $xNetwork X.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MastodonAdapter $mastodon,
		private readonly BlueskyAdapter $bluesky,
		private readonly LinkedInAdapter $linkedin,
		private readonly XAdapter $xNetwork,
	) {
	}//end __construct()

	/**
	 * The adapters, in network order.
	 *
	 * @return array<int, SocialNetworkAdapter>
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function all(): array {
		return [$this->mastodon, $this->bluesky, $this->linkedin, $this->xNetwork];
	}//end all()
}//end class
