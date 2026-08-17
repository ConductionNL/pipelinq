<?php

/**
 * Pipelinq AnalyticsController.
 *
 * Thin REST controller exposing the cross-module analytics summary
 * (Klantbeeld 360 dashboard) for authenticated users.
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
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the Klantbeeld 360 cross-module analytics summary plus
 * the dashboard analytics overview/trends/funnels endpoints.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 * @spec openspec/changes/dashboard/tasks.md#task-2.1
 */
class AnalyticsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param AnalyticsService $analyticsService Analytics summary service.
	 * @param IUserSession $userSession Active user session.
	 * @param ObjectOwnerAccessPolicy $policy Per-object owner authorization.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private AnalyticsService $analyticsService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/analytics/overview.
	 *
	 * Cross-module KPI snapshot for the unified analytics widget. Period
	 * defaults to "month" when omitted. Invalid period -> 400. OpenRegister
	 * outage -> 500 with a static message.
	 *
	 * @return JSONResponse The overview payload, or an error envelope.
	 *
	 * @spec openspec/changes/dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// These endpoints take NO object selector — they aggregate across the
		// whole instance and never pass a user to the service, so there is no
		// object to own and nothing to scope. The question is therefore not
		// "may this caller see THIS record" but "may this caller see
		// company-wide CRM analytics at all", which is exactly what
		// ObjectOwnerAccessPolicy::isPrivileged answers. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getOverview(period: $period));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] overview failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end overview()

	/**
	 * GET /api/analytics/commercial.
	 *
	 * Commercial KPI snapshot (revenue, won value, win rate, average deal
	 * size, weighted forecast, open pipeline value) for the Commercial
	 * dashboard. Period defaults to "month". Invalid period -> 400.
	 * OpenRegister outage -> 500 with a static message.
	 *
	 * @return JSONResponse The commercial overview payload, or an error envelope.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	#[NoAdminRequired]
	public function commercial(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// These endpoints take NO object selector — they aggregate across the
		// whole instance and never pass a user to the service, so there is no
		// object to own and nothing to scope. The question is therefore not
		// "may this caller see THIS record" but "may this caller see
		// company-wide CRM analytics at all", which is exactly what
		// ObjectOwnerAccessPolicy::isPrivileged answers. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getCommercialOverview(period: $period));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] commercial failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end commercial()

	/**
	 * GET /api/analytics/trends.
	 *
	 * Time-series data for the unified analytics charts. Unsupported metric
	 * -> 400 with `Unsupported metric`. Invalid period -> 400 with
	 * `Invalid period`. OpenRegister outage -> 500 (static message).
	 *
	 * @return JSONResponse The trend payload, or an error envelope.
	 *
	 * @spec openspec/changes/dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// These endpoints take NO object selector — they aggregate across the
		// whole instance and never pass a user to the service, so there is no
		// object to own and nothing to scope. The question is therefore not
		// "may this caller see THIS record" but "may this caller see
		// company-wide CRM analytics at all", which is exactly what
		// ObjectOwnerAccessPolicy::isPrivileged answers. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$metric = (string)$this->request->getParam('metric', '');
		$period = (string)$this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

		try {
			return new JSONResponse($this->analyticsService->getTrends(metric: $metric, period: $period));
		} catch (InvalidArgumentException $e) {
			// Map the (static) service exception text onto a controller-owned
			// static label so the response envelope never carries through any
			// value derived from $e->getMessage() — both branches return one of
			// two constant strings.
			$label = 'Unsupported metric';
			if ($e->getMessage() === 'Invalid period') {
				$label = 'Invalid period';
			}

			return new JSONResponse(['message' => $label], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] trends failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end trends()

	/**
	 * GET /api/analytics/funnels.
	 *
	 * Lead-to-close and request-to-resolved funnel counts.
	 *
	 * @return JSONResponse The funnel payload, or an error envelope.
	 *
	 * @spec openspec/changes/dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function funnels(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// These endpoints take NO object selector — they aggregate across the
		// whole instance and never pass a user to the service, so there is no
		// object to own and nothing to scope. The question is therefore not
		// "may this caller see THIS record" but "may this caller see
		// company-wide CRM analytics at all", which is exactly what
		// ObjectOwnerAccessPolicy::isPrivileged answers. Admins bypass.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->analyticsService->getFunnels());
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AnalyticsController] funnels failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end funnels()
}//end class
