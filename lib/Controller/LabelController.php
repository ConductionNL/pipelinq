<?php

/**
 * Pipelinq LabelController.
 *
 * Renames a label in the shared list. Adding and deleting a label go straight
 * to OpenRegister, which refuses both for anybody but an administrator; a
 * rename also rewrites the items that carry the label, so it runs here.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Labels\LabelRenameException;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POST /api/labels/{id}/rename.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 */
class LabelController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest           $request The request.
	 * @param LabelRenameService $service The rename pass.
	 * @param LoggerInterface    $logger  Logger.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
	 */
	public function __construct(
		IRequest $request,
		private LabelRenameService $service,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Rename a label and rewrite it on its leads, clients and articles.
	 *
	 * The service checks that the caller is an administrator before it reads
	 * or writes anything.
	 *
	 * @param string $id The label's id.
	 *
	 * @return JSONResponse {id, name, rewritten, queued}, or {error} with 400, 403, 404, 409 or 500.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function rename(string $id): JSONResponse {
		try {
			return new JSONResponse(
				$this->service->rename(labelId: $id, name: (string)$this->request->getParam('name', ''))
			);
		} catch (LabelRenameException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatus());
		} catch (Throwable $e) {
			$this->logger->error('LabelController: rename failed', ['id' => $id, 'exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The label could not be renamed.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end rename()
}//end class
