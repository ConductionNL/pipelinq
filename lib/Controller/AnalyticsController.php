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
 * @spec openspec/changes/archive/2026-06-14-klantbeeld-360/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\AnalyticsServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the Klantbeeld 360 cross-module analytics summary plus
 * the dashboard analytics overview/trends/funnels endpoints.
 *
 * @spec openspec/changes/archive/2026-06-14-klantbeeld-360/tasks.md#task-1.2
 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.1
 */
class AnalyticsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param AnalyticsServiceInterface $analyticsService Analytics summary service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private AnalyticsServiceInterface $analyticsService,
		private CrmPrivilegeCheck $privilege,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$period = $this->privilegedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		try {
			return new JSONResponse($this->analyticsService->getOverview(period: $period));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->unavailable(action: 'overview', error: $e);
		}
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
		$period = $this->privilegedPeriod();
		if ($period instanceof JSONResponse) {
			return $period;
		}

		try {
			$filter = $this->request->getParam('filter', []);
			if (is_array($filter) === false) {
				$filter = [];
			}

			return new JSONResponse($this->analyticsService->getCommercialOverview(period: $period, leadFilter: $filter));
		} catch (InvalidArgumentException) {
			return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->unavailable(action: 'commercial', error: $e);
		}
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
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function trends(): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$metric = (string)$this->request->getParam('metric', '');
		$period = (string)$this->request->getParam('period', AnalyticsServiceInterface::DEFAULT_PERIOD);

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
			return $this->unavailable(action: 'trends', error: $e);
		}
	}//end trends()

	/**
	 * GET /api/analytics/funnels.
	 *
	 * Lead-to-close and request-to-resolved funnel counts.
	 *
	 * @return JSONResponse The funnel payload, or an error envelope.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function funnels(): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->analyticsService->getFunnels());
		} catch (\Throwable $e) {
			return $this->unavailable(action: 'funnels', error: $e);
		}
	}//end funnels()

	/**
	 * The 401/403 for a caller who may not see analytics, or null.
	 *
	 * These endpoints take NO object selector — they aggregate across the
	 * whole instance and never pass a user to the service, so there is no
	 * object to own and nothing to scope. The question is therefore not
	 * "may this caller see THIS record" but "may this caller see
	 * company-wide CRM analytics at all", which is exactly what
	 * ObjectOwnerAccessPolicy::isPrivileged answers. Admins bypass.
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(): ?JSONResponse {
		return $this->privilege->refusalWith(['message' => 'Unauthorized'], ['message' => 'Forbidden']);
	}//end refusal()

	/**
	 * The requested period for a caller who may see analytics, or the refusal.
	 *
	 * @return string|JSONResponse
	 */
	private function privilegedPeriod(): string|JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return (string)$this->request->getParam('period', AnalyticsServiceInterface::DEFAULT_PERIOD);
	}//end privilegedPeriod()

	/**
	 * Log a failed read and answer with the static 500.
	 *
	 * @param string     $action The failed endpoint, for the log.
	 * @param \Throwable $error  The failure.
	 *
	 * @return JSONResponse
	 */
	private function unavailable(string $action, \Throwable $error): JSONResponse {
		$this->logger->warning(
			message: '[AnalyticsController] ' . $action . ' failed',
			context: ['error' => $error->getMessage()]
		);
		return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end unavailable()
}//end class
