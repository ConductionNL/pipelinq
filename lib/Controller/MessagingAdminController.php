<?php

/**
 * Pipelinq MessagingAdminController.
 *
 * Admin endpoints for the account the SMS and WhatsApp provider webhooks write
 * as. Both are admin only: the account decides the identity every inbound
 * message, conversation update and contact moment from a provider is saved
 * with.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-001-messaging-provider-administration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\MessagingServiceAccount;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and picks the messaging service account.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-001-messaging-provider-administration
 */
class MessagingAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request        The request.
	 * @param MessagingServiceAccount $serviceAccount The account the webhooks write as.
	 * @param IUserSession            $userSession    The caller.
	 * @param IGroupManager           $groupManager   Answers whether the caller is an admin.
	 */
	public function __construct(
		IRequest $request,
		private readonly MessagingServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Which account the webhooks write as, and whether it can be used.
	 *
	 * @auth admin-only Names the account every webhook write runs as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse `{userId, usable, reason, group}`, or 403.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-001-messaging-provider-administration
	 */
	public function getServiceAccount(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->body(status: $this->serviceAccount->status()), Http::STATUS_OK);
	}//end getServiceAccount()

	/**
	 * Pick the account the webhooks write as. It must exist and be enabled; it
	 * joins the messaging service group.
	 *
	 * @auth admin-only Chooses the identity every webhook write runs as; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse The new status, 400 when the account cannot be used, or 403.
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-001-messaging-provider-administration
	 */
	public function saveServiceAccount(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$status = $this->serviceAccount->assign(userId: (string)$this->request->getParam('userId', ''));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['errorCode' => 'badRequest', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->body(status: $status), Http::STATUS_OK);
	}//end saveServiceAccount()

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
		return array_merge($status, ['group' => MessagingServiceAccount::GROUP]);
	}//end body()
}//end class
