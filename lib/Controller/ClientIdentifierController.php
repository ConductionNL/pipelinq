<?php

/**
 * Pipelinq ClientIdentifierController.
 *
 * Checks one of a client's official numbers against its register on request
 * (clients-registry-numbers-and-enrichment, REQ-CRN-002). The client is read
 * and written as the calling user, so OpenRegister's object authorisation
 * decides who may check which client.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The Check action of a client's Identifiers section.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md#requirement-a-number-can-be-checked-against-its-register-req-crn-002
 */
class ClientIdentifierController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param ClientIdentifierServiceInterface $identifiers The checks.
	 */
	public function __construct(
		IRequest $request,
		private readonly ClientIdentifierServiceInterface $identifiers,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check one identifier of the client and answer it with its new status.
	 *
	 * The object authorisation check: the client is first read with the
	 * caller's rights, and one they may not open (or that does not exist)
	 * answers 403 before anything is looked up. The outcome is then written
	 * with the same rights, so a client they may read but not change answers
	 * 403 too.
	 *
	 * @param string $id The client's id.
	 *
	 * @return JSONResponse The checked identifier, or the refusal.
	 *
	 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-2.4
	 */
	#[NoAdminRequired]
	public function check(string $id): JSONResponse {
		// The object check: a client the caller may not open is not checked at all.
		if ($this->identifiers->canAccessClient(clientId: $id) === false) {
			return new JSONResponse(data: ['message' => 'You may not open this client.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$answer = $this->identifiers->check(
			clientId: $id,
			scheme: trim((string)$this->request->getParam('scheme', '')),
			value: trim((string)$this->request->getParam('value', ''))
		);
		$status = (int)$answer['status'];
		unset($answer['status']);

		return new JSONResponse(data: $answer, statusCode: $status);
	}//end check()
}//end class
