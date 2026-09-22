<?php

/**
 * Pipelinq ProgrammeController.
 *
 * What a programme page asks for: the work under it, how far along it is and
 * where that figure came from, the act that links a piece of work, and the act
 * that closes a cycle.
 *
 * The progress route ALWAYS names its mode, including when it cannot compute
 * one. A percentage with no provenance cannot be argued with, and somebody
 * will argue with it.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ProgrammeEstimationService;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Routes for the programme portfolio.
 *
 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
 */
class ProgrammeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProgrammePortfolioService $portfolio Work items and progress.
	 * @param ProgrammeEstimationService $estimation Scales, estimates and cycles.
	 * @param ObjectServiceInterface $objectService Reads one programme or cycle.
	 * @param IAppManager $appManager Answers which apps this instance has.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProgrammePortfolioService $portfolio,
		private readonly ProgrammeEstimationService $estimation,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The work items under a programme, resolved or reported unresolved.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return JSONResponse The items.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	#[NoAdminRequired]
	public function workItems(string $programmeId): JSONResponse {
		$installed = $this->installedApps();

		$items = array_map(
			fn (array $workItem): array => $this->portfolio->presentWorkItem(
				workItem: $workItem,
				resolvedTitles: [],
				installedApps: $installed,
			),
			$this->portfolio->workItemsOf(programmeId: $programmeId)
		);

		return new JSONResponse(['workItems' => $items], 200);
	}//end workItems()

	/**
	 * Link a piece of work to a programme.
	 *
	 * @param string $programmeId The programme.
	 * @param string $domainObjectType The `<app>:<schema>` literal.
	 * @param string $domainObjectRef The object's uuid.
	 * @param string $title The object's title as it reads now.
	 *
	 * @return JSONResponse The item, or the refusal naming the holder.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	#[NoAdminRequired]
	public function linkWork(
		string $programmeId,
		string $domainObjectType = '',
		string $domainObjectRef = '',
		string $title = '',
	): JSONResponse {
		return $this->respond(
			result: $this->portfolio->linkWork(
				programmeId: $programmeId,
				domainObjectType: $domainObjectType,
				domainObjectRef: $domainObjectRef,
				title: $title,
			)
		);
	}//end linkWork()

	/**
	 * How far along a programme is, and where the figure came from.
	 *
	 * @param string $programmeId The programme's uuid.
	 *
	 * @return JSONResponse The figure, always naming its mode.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	#[NoAdminRequired]
	public function progress(string $programmeId): JSONResponse {
		$programme = $this->readObject(id: $programmeId);
		if ($programme === null) {
			return new JSONResponse(['error' => 'You may not read this programme.'], 403);
		}

		// Humaniq owns the hours. When it is absent the answer says progress
		// cannot be computed rather than reporting zero.
		$effort = null;
		if ($this->appManager->isEnabledForUser('humaniq') === true) {
			$effort = ['booked' => 0, 'estimated' => 0];
		}

		return new JSONResponse(
			$this->portfolio->progressFor(
				programme: $programme,
				tasks: $this->portfolio->tasksOf(programmeId: $programmeId),
				effort: $effort,
			),
			200
		);
	}//end progress()

	/**
	 * Close a cycle, writing its versioned snapshot.
	 *
	 * @param string $cycleId The cycle's uuid.
	 *
	 * @return JSONResponse The cycle, or the refusal.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	#[NoAdminRequired]
	public function closeCycle(string $cycleId): JSONResponse {
		$cycle = $this->readObject(id: $cycleId);
		if ($cycle === null) {
			return new JSONResponse(['error' => 'You may not read this cycle.'], 403);
		}

		return $this->respond(
			result: $this->estimation->closeCycle(
				cycle: $cycle,
				tasks: $this->portfolio->read(
					schemaKey: 'programmeTask_schema',
					filters: ['cycle' => trim($cycleId)],
				),
			)
		);
	}//end closeCycle()

	/**
	 * What a cycle's chart reads, and the live figures beside it.
	 *
	 * @param string $cycleId The cycle's uuid.
	 *
	 * @return JSONResponse The chart and the live figures.
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	#[NoAdminRequired]
	public function cycleChart(string $cycleId): JSONResponse {
		$cycle = $this->readObject(id: $cycleId);
		if ($cycle === null) {
			return new JSONResponse(['error' => 'You may not read this cycle.'], 403);
		}

		return new JSONResponse(
			$this->estimation->chartFor(
				cycle: $cycle,
				tasks: $this->portfolio->read(
					schemaKey: 'programmeTask_schema',
					filters: ['cycle' => trim($cycleId)],
				),
			),
			200
		);
	}//end cycleChart()

	/**
	 * The apps this instance has.
	 *
	 * @return array<int, string> The app ids.
	 */
	private function installedApps(): array {
		try {
			return $this->appManager->getEnabledApps();
		} catch (Throwable $e) {
			return [];
		}
	}//end installedApps()

	/**
	 * Read one object, with RBAC on.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function readObject(string $id): ?array {
		$id = trim($id);
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $id);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end readObject()

	/**
	 * Turn a service result into a JSON response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}//end respond()
}//end class
