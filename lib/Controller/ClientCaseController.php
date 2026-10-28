<?php

/**
 * Pipelinq ClientCaseController.
 *
 * GET /api/clients/{id}/cases: the client's cases from dossiq, for the client
 * page's Zaken card, Open zaken and Zaken tab.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientCaseReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers a client's cases.
 *
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
 */
class ClientCaseController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest         $request The request.
	 * @param ClientCaseReader $reader  Reads the client's cases.
	 */
	public function __construct(
		IRequest $request,
		private readonly ClientCaseReader $reader,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The client's cases, `{ results, total, available }`. The client is read
	 * as the caller first: a client the caller may not read answers 403, and
	 * dossiq reads the cases with the caller's rights.
	 *
	 * @param string $id     The client uuid.
	 * @param string $status `open`, `closed` or `all`.
	 * @param int    $limit  The most rows to return.
	 *
	 * @return JSONResponse The cases, or the refusal.
	 *
	 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-client-page-lists-the-clients-cases
	 */
	#[NoAdminRequired]
	public function index(string $id, string $status = 'all', int $limit = 20): JSONResponse {
		$answer = $this->reader->casesOf(clientId: $id, status: $status, limit: $limit);
		$code = (int)$answer['status'];
		unset($answer['status']);

		return new JSONResponse(data: $answer, statusCode: $code);
	}//end index()
}//end class
