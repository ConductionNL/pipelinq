<?php

/**
 * Pipelinq ExportAdminGate.
 *
 * The export-admin check the export job and run endpoints run before any work.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Export\ExportAccessPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Answers who is calling an export endpoint, and refuses a caller who is not
 * signed in (401) or who is not an export admin or analyst (403).
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */
final class ExportAdminGate {
	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession The user session.
	 * @param ExportAccessPolicy $policy      The export access policy.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ExportAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The acting user UID (empty when no session).
	 *
	 * @return string The UID.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
	 */
	public function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end userId()

	/**
	 * The refusal for a caller who may not use the export endpoints, or null.
	 *
	 * @param IL10N $l10n The localization service.
	 *
	 * @return JSONResponse|null 401 without a session, 403 without the export role, null otherwise.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
	 */
	public function refusal(IL10N $l10n): ?JSONResponse {
		$uid = $this->userId();
		if ($uid === '') {
			return new JSONResponse(['error' => $l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->isExportAdmin(userId: $uid) === false) {
			return new JSONResponse(['error' => $l10n->t('Insufficient permissions')], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()
}//end class
