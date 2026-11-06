<?php

/**
 * Pipelinq LeadRelatedSummaryController.
 *
 * GET /api/leads/{id}/related-summary: the client's earlier deals and the
 * hours on the lead, for the lead page's Related card.
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
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Lead\LeadRelatedSummary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The lead page's Related summary.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 */
class LeadRelatedSummaryController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request       The request.
	 * @param IUserSession            $userSession   The user session.
	 * @param ObjectOwnerAccessPolicy $policy        Who may read the lead.
	 * @param IAppConfig              $appConfig     App config (register, schema).
	 * @param ObjectServiceInterface  $objectService OpenRegister objects.
	 * @param LeadRelatedSummary      $summary       The summary.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IAppConfig $appConfig,
		private ObjectServiceInterface $objectService,
		private LeadRelatedSummary $summary,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The summary of one lead the caller may read.
	 *
	 * @param string $id The lead's id.
	 *
	 * @return JSONResponse `{ earlier, hours }`, or an error.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		try {
			$found = $this->objectService->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$found = null;
		}

		$lead = [];
		if ($found !== null) {
			$lead = (array)$found->jsonSerialize();
		}

		if ($lead === []) {
			return new JSONResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$lead['id'] = ($lead['id'] ?? $id);
		if ($this->policy->mayAccess(uid: $user->getUID(), object: $lead, ownerField: 'assignee') === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->summary->forLead(lead: $lead));
	}//end show()
}//end class
