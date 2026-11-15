<?php

/**
 * Pipelinq MarketingCallerGate.
 *
 * The caller check and the generic 404 shared by the marketing controllers.
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

use OCA\Pipelinq\Controller\CrmCallerGate;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The CRM caller gate and the generic 404 of the blast, segment and template
 * controllers. The host class provides IUserSession `$userSession` and
 * ObjectOwnerAccessPolicy `$policy` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait MarketingCallerGate {

	/**
	 * Generic 404 response.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * The caller check shared by the marketing controllers.
	 *
	 * @return CrmCallerGate The gate.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function crmGate(): CrmCallerGate {
		return new CrmCallerGate(userSession: $this->userSession, policy: $this->policy);
	}//end crmGate()
}//end trait
