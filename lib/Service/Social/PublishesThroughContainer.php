<?php

/**
 * Pipelinq PublishesThroughContainer.
 *
 * A network that publishes in two steps: it creates a container first and
 * publishes that container second.
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
 * A network adapter whose publish goes through a container.
 *
 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
 */
interface PublishesThroughContainer {
	/**
	 * The SECOND of the two calls: publish the prepared container.
	 *
	 * @param SocialPublishRequest $request     The resolved post.
	 * @param string               $containerId The id the container step answered with.
	 *
	 * @return SocialAdapterCall The publish request.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function publishContainerCall(SocialPublishRequest $request, string $containerId): SocialAdapterCall;
}//end interface
