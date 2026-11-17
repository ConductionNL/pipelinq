<?php

/**
 * Pipelinq ExportAdminAction.
 *
 * The export-admin gate and guarded action shared by the export controllers.
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

use OCA\Pipelinq\Controller\ExportActionResponse;
use OCA\Pipelinq\Controller\ExportAdminGate;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Runs an export action behind the export-admin gate. The host class provides
 * IUserSession `$userSession`, ExportAccessPolicyInterface `$policy`, IL10N
 * `$l10n` and LoggerInterface `$logger` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ExportAdminAction {

	/**
	 * Require export-admin / analyst authorization, then run the action with
	 * shared error handling.
	 *
	 * Returns 401 when unauthenticated, 403 when not an export admin/analyst,
	 * 404 / 422 from the service's OCS exceptions, 500 otherwise (generic message).
	 *
	 * @param callable $action The action.
	 * @param string   $label  Log label.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function requireExportAdmin(callable $action, string $label): JSONResponse {
		$refusal = $this->exportGate()->refusal(l10n: $this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$response = new ExportActionResponse(l10n: $this->l10n, logger: $this->logger);
		return $response->respond(action: $action, label: substr(strrchr(self::class, '\\'), 1) . '::' . $label);
	}//end requireExportAdmin()

	/**
	 * The export-admin gate over this request's session.
	 *
	 * @return ExportAdminGate The gate.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function exportGate(): ExportAdminGate {
		return new ExportAdminGate(userSession: $this->userSession, policy: $this->policy);
	}//end exportGate()
}//end trait
