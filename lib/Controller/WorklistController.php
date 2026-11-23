<?php

/**
 * Pipelinq WorklistController.
 *
 * Thin REST controller exposing the canonical "my work" union (current
 * user's leads + requests) for authenticated users. Replaces the union
 * logic that was duplicated client-side between the MyWorkWidget
 * dashboard widget and the MyWork page.
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
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the "my work" worklist endpoint feeding the dashboard
 * MyWork widget (top-5) and the MyWork page (full list).
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
 */
class WorklistController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param WorklistServiceInterface $worklistService Worklist union service.
	 * @param IUserSession $userSession Active user session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private WorklistServiceInterface $worklistService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/worklist/mine.
	 *
	 * The current user's worklist: union of leads + requests assigned to
	 * the session user (assignee scoping is applied server-side; callers
	 * cannot request another user's worklist). Optional `?limit=` caps
	 * the returned rows (the dashboard widget uses 5) while `total`,
	 * `leadCount` and `requestCount` always reflect the full union.
	 * Invalid limit -> 400. OpenRegister outage -> 500 with a static
	 * message. `?scope=team|organisation` widens whose work is listed when
	 * the user may (REQ-MW-180), `?member=` narrows the team to one member;
	 * a scope or member the user may not ask for -> 400.
	 *
	 * @return JSONResponse The worklist payload, or an error envelope.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	#[NoAdminRequired]
	public function mine(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$limitParam = $this->request->getParam('limit', '');
		$limit = null;
		if ($limitParam !== '' && $limitParam !== null) {
			if (is_string($limitParam) === false || ctype_digit($limitParam) === false || ((int)$limitParam) < 1) {
				return new JSONResponse(['message' => 'Invalid limit'], Http::STATUS_BAD_REQUEST);
			}

			$limit = (int)$limitParam;
		}

		[$scope, $member] = $this->workScopeParams();

		try {
			return new JSONResponse($this->worklistService->getMine(userId: $user->getUID(), limit: $limit, scope: $scope, member: $member));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid scope'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistController] mine failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Worklist unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end mine()

	/**
	 * Whose work `mine` asks for: `?scope=` (default `mine`) and `?member=`.
	 *
	 * @return array{0: string, 1: string} The scope and the member ('' for none).
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	private function workScopeParams(): array {
		$scope = (string)$this->request->getParam('scope', '');
		if ($scope === '') {
			$scope = 'mine';
		}

		return [$scope, (string)$this->request->getParam('member', '')];
	}//end workScopeParams()

	/**
	 * GET /api/worklist/items: every open item of the session user's work
	 * (tickets, leads, follow-up tasks) for one of My work's lists
	 * (`?bucket=answer-today|waiting-on-me|waiting-on-customer`) and lens
	 * (`?lens=tickets|leads|tasks`), in `?sort=deadline|waiting|newest|priority`
	 * order, finished work too with `?done=1`. With `?format=csv` the items download as
	 * a file (the page's Downloaden).
	 *
	 * @return JSONResponse|DataDownloadResponse The items, the file, or an error envelope.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	#[NoAdminRequired]
	public function items(): JSONResponse|DataDownloadResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$bucket = (string)$this->request->getParam('bucket', '');
		if (in_array($bucket, ['', 'answer-today', 'waiting-on-me', 'waiting-on-customer'], true) === false) {
			return new JSONResponse(['message' => 'Invalid bucket'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->fetchItems(user: $user, bucket: $bucket);
		if ($result instanceof JSONResponse) {
			return $result;
		}

		return $this->itemsResponse(result: $result);
	}//end items()

	/**
	 * Read the items of a bucket with the requested lens, sort, finished work
	 * and scope (`?scope=mine|team|organisation`, `?member=`, REQ-MW-180).
	 *
	 * @param IUser $user The signed-in user.
	 * @param string $bucket The validated bucket.
	 *
	 * @return array<string, mixed>|JSONResponse The worklist result, a 400 for a scope the user may not
	 *   ask for, or a 500 envelope when it is unavailable.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	private function fetchItems(IUser $user, string $bucket): array|JSONResponse {
		[$scope, $member] = $this->workScopeParams();
		$view = [
			'bucket' => $bucket,
			'lens'   => (string)$this->request->getParam('lens', ''),
			'sort'   => (string)$this->request->getParam('sort', 'deadline'),
			'done'   => $this->doneParam(),
			'scope'  => $scope,
			'member' => $member,
		];

		try {
			return $this->worklistService->items(userId: $user->getUID(), view: $view);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid scope'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistController] items failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Worklist unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end fetchItems()

	/**
	 * Whether finished work is asked for too (`?done=1` or `?done=true`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	private function doneParam(): bool {
		return in_array((string)$this->request->getParam('done', ''), ['1', 'true'], true);
	}//end doneParam()

	/**
	 * The worklist result as JSON, or as a CSV download when asked for.
	 *
	 * @param array<string, mixed> $result The worklist result.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	private function itemsResponse(array $result): JSONResponse|DataDownloadResponse {
		if ((string)$this->request->getParam('format', '') === 'csv') {
			return new DataDownloadResponse($this->asCsv(items: $result['items']), 'my-work.csv', 'text/csv');
		}

		return new JSONResponse($result);
	}//end itemsResponse()

	/**
	 * GET /api/worklist/summary: one sentence on the session user's open work,
	 * for My work's header (`{ text }`).
	 *
	 * @return JSONResponse The sentence, or an error envelope.
	 *
	 * @spec openspec/specs/my-work/spec.md#requirement-my-work-lists-all-my-items-in-one-list
	 */
	#[NoAdminRequired]
	public function summary(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['text' => $this->worklistService->summary(userId: $user->getUID())]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistController] summary failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Worklist unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end summary()

	/**
	 * The items as CSV: kind, title, priority, due date, list.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return string The CSV text.
	 */
	private function asCsv(array $items): string {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, ['kind', 'title', 'priority', 'due', 'list'], ',', '"', '');
		foreach ($items as $item) {
			fputcsv(
				$handle,
				[$item['kind'], $item['title'], $item['priority'], (string)($item['dueAt'] ?? ''), $item['bucket']],
				',',
				'"',
				''
			);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);
		return $csv;
	}//end asCsv()
}//end class
