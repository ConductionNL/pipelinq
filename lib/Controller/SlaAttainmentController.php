<?php

/**
 * Pipelinq SlaAttainmentController.
 *
 * Thin REST controller exposing SLA attainment aggregations to the
 * front-end dashboard widget.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /api/sla/attainment — SLA attainment aggregations.
 *
 * All authenticated users may view org-level KPIs (REQ-006). Per-team
 * scoping is honoured by the underlying ObjectService row-level RBAC
 * (out of controller scope).
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaAttainmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param SlaAttainmentService $attainment Attainment service.
	 * @param IUserSession $userSession Active session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy Per-object owner access policy.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private SlaAttainmentService $attainment,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/sla/attainment.
	 *
	 * @return JSONResponse The attainment payload, or an error envelope.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	#[NoAdminRequired]
	public function attainment(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'notAuthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// SLA attainment reports across the whole service desk — a CRM
		// capability, not an any-authenticated-user one.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$params = [
			'bucket' => $this->request->getParam('bucket', 'month'),
			'date' => $this->request->getParam('date', ''),
			'week' => $this->request->getParam('week', ''),
			'month' => $this->request->getParam('month', ''),
			'quarter' => $this->request->getParam('quarter', ''),
			'groupBy' => $this->request->getParam('groupBy', 'policy'),
			'policy' => $this->request->getParam('policy', ''),
		];

		try {
			$payload = $this->attainment->compute($params);
			return new JSONResponse($payload);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaAttainmentController: compute failed',
				['error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'computeFailed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end attainment()
}//end class
