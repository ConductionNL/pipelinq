<?php

/**
 * Pipelinq PrivilegedAppointmentCaller.
 *
 * The CRM-privilege check of the appointment figure and eligibility controllers.
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
 * Answers the session user's id for a CRM-privileged caller, a 401 without a
 * session and a 403 otherwise. The host class provides IUserSession
 * `$userSession`, ObjectOwnerAccessPolicy `$accessPolicy` and IL10N `$l10n`
 * properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PrivilegedAppointmentCaller {

	/**
	 * The privileged caller's uid, or the 401/403 that refuses the caller.
	 *
	 * @return string|JSONResponse The uid, or an error response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function privilegedUid(): string|JSONResponse {
		$uid = ($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(data: ['error' => $this->l10n->t('Authentication required')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Forbidden')], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $uid;
	}//end privilegedUid()
}//end trait
