<?php

/**
 * Pipelinq BackgroundServiceAccountController.
 *
 * The admin endpoints that read and pick the background service account.
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
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and picks the background service account.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */
class BackgroundServiceAccountController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request        The request.
	 * @param BackgroundServiceAccount $serviceAccount The account background work writes as.
	 * @param IUserSession             $userSession    The caller.
	 * @param IGroupManager            $groupManager   Answers whether the caller is an admin.
	 */
	public function __construct(
		IRequest $request,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which account background work writes as, and whether it can be used.
	 *
	 * @auth admin-only Names the account background writes run as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse `{userId, usable, reason, group}`, or 403.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function show(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->body(status: $this->serviceAccount->status()), Http::STATUS_OK);
	}//end show()

	/**
	 * Pick the account background work writes as. It must exist and be
	 * enabled; it joins the background service group.
	 *
	 * @auth admin-only Chooses the identity background writes run as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse The new status, 400 when the account cannot be used, or 403.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function save(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$status = $this->serviceAccount->assign(userId: (string)$this->request->getParam('userId', ''));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['errorCode' => 'badRequest', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->body(status: $status), Http::STATUS_OK);
	}//end save()

	/**
	 * Whether the caller is a Nextcloud admin.
	 *
	 * @return bool True for an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}//end isAdmin()

	/**
	 * The status with the group the account must be in.
	 *
	 * @param array{userId: string, usable: bool, reason: string|null} $status The status.
	 *
	 * @return array<string, mixed> The response body.
	 */
	private function body(array $status): array {
		return array_merge($status, ['group' => BackgroundServiceAccount::GROUP]);
	}//end body()
}//end class
