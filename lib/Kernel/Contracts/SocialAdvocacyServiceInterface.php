<?php

/**
 * Contract of SocialAdvocacyService
 *
 * The public surface of SocialAdvocacyService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of SocialAdvocacyService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SocialAdvocacyServiceInterface {

	/**
	 * Record that the owner posted it.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller, who must be the account's owner or an administrator.
	 * @param string $url Where it landed, when they know.
	 *
	 * @return array{error?: string, publication?: array<string, mixed>} The updated row, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function confirmShare(string $publicationId, string $uid, string $url = ''): array;

	/**
	 * Everything the owner needs to post it: the prepared text, the media and
	 * a deep link into the network's own composer.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, share?: array<string, mixed>} The bundle, or an error.
	 *
	 * @spec openspec/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function shareBundle(string $publicationId, string $uid): array;
}//end interface
