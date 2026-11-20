<?php

/**
 * Pipelinq MailIntakeAdminController.
 *
 * Admin endpoints for the ticket mailboxes (requests-inbound-to-queue D4):
 * the Nextcloud Mail accounts whose inbox opens tickets in the Queue. Both
 * are admin only: the list decides whose mail becomes a ticket, and the
 * account list names every Mail account on the instance and its owner.
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
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\MailIntake\MailIntakeAccounts;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists the Mail accounts and stores which are ticket mailboxes.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
 */
class MailIntakeAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param MailIntakeAccounts $accounts     The Mail accounts and the ticket mailbox list.
	 * @param IUserSession       $userSession  The caller.
	 * @param IGroupManager      $groupManager Answers whether the caller is an admin.
	 */
	public function __construct(
		IRequest $request,
		private readonly MailIntakeAccounts $accounts,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every Mail account with its address and owner, and which are ticket mailboxes.
	 *
	 * @auth admin-only Names every Mail account on the instance and its owner; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse `{accounts: [{id, address, name, owner}], selected: [id]}`, or 403.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function accounts(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['accounts' => $this->accounts->all(), 'selected' => $this->accounts->selected()], Http::STATUS_OK);
	}//end accounts()

	/**
	 * Store which Mail accounts are ticket mailboxes.
	 *
	 * @auth admin-only Decides whose mail opens tickets; the body additionally checks the caller is an admin.
	 *
	 * @return JSONResponse `{selected: [id]}`, 400 without a list, or 403.
	 *
	 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-names-the-ticket-mailboxes-req-itq-003
	 */
	public function saveAccounts(): JSONResponse {
		if ($this->isAdmin() === false) {
			return new JSONResponse(['errorCode' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$ids = $this->request->getParam('accounts');
		if (is_array($ids) === false) {
			return new JSONResponse(['errorCode' => 'badRequest', 'message' => 'accounts must be a list of Mail account ids'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['selected' => $this->accounts->select(values: $ids)], Http::STATUS_OK);
	}//end saveAccounts()

	/**
	 * Whether the caller is a Nextcloud admin.
	 *
	 * @return bool True for an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID()) === true;
	}//end isAdmin()
}//end class
