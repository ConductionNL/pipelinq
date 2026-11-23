<?php

/**
 * Pipelinq MyWorkTeamController.
 *
 * Whose work My work can show the signed-in user, and the members of their
 * team with their open work.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCA\Pipelinq\Service\Worklist\WorkScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/my-work/team: the scope switch and the member list of My work.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 */
class MyWorkTeamController extends Controller {

	/**
	 * The English label of each scope; the page translates it.
	 *
	 * @var array<string, string>
	 */
	private const SCOPE_LABELS = [
		WorkScope::MINE => 'My items',
		WorkScope::TEAM => 'Team',
		WorkScope::ORGANISATION => 'Organisation',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request         The request.
	 * @param IUserSession             $userSession     The signed-in user.
	 * @param WorkScope                $workScope       Which scopes the user has and who is in their team.
	 * @param WorklistServiceInterface $worklistService The team's open work.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly WorkScope $workScope,
		private readonly WorklistServiceInterface $worklistService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The scopes the user may switch between (`scopes`, empty when they have
	 * only their own work, so the switch stays hidden) and, for a team
	 * manager, the members of their team with how many open items each
	 * holds (`members`, each `{ value, label, count }`).
	 *
	 * @return JSONResponse The scopes and members, 401 without a session, 500 when OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$scopes = $this->workScope->available(userId: $userId);
		$options = [];
		if (count($scopes) > 1) {
			$options = array_map(static fn (string $scope): array => ['value' => $scope, 'label' => self::SCOPE_LABELS[$scope]], $scopes);
		}

		$members = [];
		try {
			if (in_array(WorkScope::TEAM, $scopes, true) === true) {
				$members = $this->members(userId: $userId);
			}
		} catch (\RuntimeException $e) {
			// The worklist logged why OpenRegister could not be read.
			return new JSONResponse(['message' => 'Team unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['scopes' => $options, 'members' => $members]);
	}//end show()

	/**
	 * The team's members, each with the number of open items assigned to them.
	 *
	 * @param string $userId The manager's UID.
	 *
	 * @return array<int, array{value: string, label: string, count: int}> The members.
	 */
	private function members(string $userId): array {
		$open = [];
		foreach ($this->worklistService->items(userId: $userId, view: ['scope' => WorkScope::TEAM])['items'] as $item) {
			$record   = (array)($item['record'] ?? []);
			$assignee = (string)($record['assignee'] ?? ($record['assigneeUserId'] ?? ''));
			$open[$assignee] = (($open[$assignee] ?? 0) + 1);
		}

		$members = [];
		foreach ($this->workScope->team(userId: $userId) as $uid => $name) {
			$uid       = (string)$uid;
			$members[] = ['value' => $uid, 'label' => $name, 'count' => ($open[$uid] ?? 0)];
		}

		return $members;
	}//end members()
}//end class
