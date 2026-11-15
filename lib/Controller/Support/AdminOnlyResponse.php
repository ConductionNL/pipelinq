<?php

/**
 * Pipelinq AdminOnlyResponse.
 *
 * The 401/403 response for a caller who is not a signed-in administrator.
 *
 * @category Util
 * @package  OCA\Pipelinq\Controller\Support
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Answers 401 without a session user and 403 for a non-admin, null otherwise.
 * The host class provides IUserSession `$userSession`, IGroupManager
 * `$groupManager` and IL10N `$l10n` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait AdminOnlyResponse {

	/**
	 * Require an authenticated admin user.
	 *
	 * @return null|JSONResponse Null when the caller is an admin; 401/403 otherwise.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Admin privileges required')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireAdmin()
}//end trait
