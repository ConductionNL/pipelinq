<?php

/**
 * Pipelinq full data export controller.
 *
 * Starts "Export all data" (POST /api/export/full). The archive holds every
 * object regardless of per-user read rules, so only an administrator may start
 * it; the work runs in FullExportJob.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\BackgroundJob\FullExportJob;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\Export\FullExportState;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Starts the full data export.
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */
class FullExportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request     The request.
	 * @param IUserSession  $userSession The caller.
	 * @param IGroupManager $groups      Whether the caller is an administrator.
	 * @param IJobList      $jobs        The background job queue.
	 * @param FullExportState|null $state The state of the last export, for the page.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly IJobList $jobs,
		private readonly ?FullExportState $state = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The state of the last export: none, queued, ready (with the archive's
	 * name and file id) or failed. Administrators only, as starting one.
	 *
	 * @return JSONResponse 200 with the state, 401 without a caller, 403 for anyone but an administrator.
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function status(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Sign in to export data.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groups->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Only an administrator can export all data.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(($this->state?->read() ?? ['state' => 'none']));
	}//end status()

	/**
	 * Queue the export for the calling administrator.
	 *
	 * A delegated admin setting is not enough: the archive bypasses every
	 * per-user read rule, so the caller must be a Nextcloud administrator.
	 *
	 * @return JSONResponse 202 `{status: queued}`, 401 without a caller, 403 for anyone but an administrator.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function start(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Sign in to export data.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groups->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Only an administrator can export all data.'], Http::STATUS_FORBIDDEN);
		}

		$this->jobs->add(FullExportJob::class, ['userId' => $user->getUID()]);
		$this->state?->queued(userId: $user->getUID());

		return new JSONResponse(
			['status' => 'queued', 'message' => 'The archive is being prepared. You get a notification when it is in your Files.'],
			Http::STATUS_ACCEPTED
		);
	}//end start()
}//end class
