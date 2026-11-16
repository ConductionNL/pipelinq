<?php

/**
 * Pipelinq PostMetricsCall.
 *
 * The metrics request of one published post.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social\Support
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social\Support;

use OCA\Pipelinq\Service\Social\SocialAdapterCall;

/**
 * A GET for a post's metrics, or no request when the post has no id.
 *
 * @spec exclude shared plumbing of the social adapters, no spec surface of its own.
 */
trait PostMetricsCall {

	/**
	 * GET the path for a post, or null when the post id is blank.
	 *
	 * @param string $externalId The post id.
	 * @param string $path The request path.
	 *
	 * @return SocialAdapterCall|null The request, or null without an id.
	 *
	 * @spec exclude shared plumbing of the social adapters, no spec surface of its own.
	 */
	private function postMetricsGet(string $externalId, string $path): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(method: 'GET', path: $path);
	}//end postMetricsGet()
}//end trait
