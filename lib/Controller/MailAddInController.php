<?php

/**
 * Pipelinq MailAddInController.
 *
 * The three calls a mail client's side pane makes about the mail that is open:
 * look the sender up, add the sender as a contact, and log the mail as a
 * contact moment. The pane signs in with an app password and sends it on every
 * call, so no call here is accepted on a browser session alone.
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
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailAddIn\OpenMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Sender lookup, add as contact and log this mail, for a mail client's pane.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */
class MailAddInController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Who is calling.
	 * @param OpenMailService $openMail Sender lookup, add as contact and mail logging.
	 * @param ObjectOwnerAccessPolicy $policy Who may create contacts.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OpenMailService $openMail,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Refuse a call that does not come from a signed-in pane.
	 *
	 * These routes skip the CSRF token, because the pane has no session to
	 * take one from. So a call must carry its own credentials in an
	 * `Authorization` header (the app password of the login flow). A browser
	 * never adds that header to a request another site starts, which is what
	 * keeps a logged-in colleague's session from being ridden.
	 *
	 * @return array{0: string, 1: JSONResponse|null} The caller's user id, or a refusal.
	 */
	private function caller(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['', new JSONResponse(['error' => 'Sign in first.'], Http::STATUS_UNAUTHORIZED)];
		}

		if (trim((string)$this->request->getHeader('Authorization')) === '') {
			return [
				'',
				new JSONResponse(
					['error' => 'Send the app password of the add-in with this call.'],
					Http::STATUS_FORBIDDEN
				),
			];
		}

		return [$user->getUID(), null];
	}//end caller()

	/**
	 * POST /api/mail-add-in/lookup: the records the sender's address belongs
	 * to, as far as the caller may read them.
	 *
	 * @return JSONResponse `{ matches, organisation }`, or a refusal.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function lookup(): JSONResponse {
		[, $refusal] = $this->caller();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse($this->openMail->lookup(email: (string)$this->request->getParam('email', '')));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end lookup()

	/**
	 * POST /api/mail-add-in/contact: add the sender as a contact person, the
	 * Nextcloud contact first.
	 *
	 * Creating contacts is for colleagues who work with clients, the same
	 * rule the Contacts page applies. A client named in the call must be one
	 * the caller may read.
	 *
	 * @return JSONResponse The created contact (201), or a refusal.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-pane-shows-the-senders-record-or-adds-it-req-mai-002
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contact(): JSONResponse {
		[$uid, $refusal] = $this->caller();
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->policy->isPrivileged(uid: $uid) === false) {
			return new JSONResponse(['error' => 'You may not add contacts.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$created = $this->openMail->addSender(
				name: (string)$this->request->getParam('name', ''),
				email: (string)$this->request->getParam('email', ''),
				client: (string)$this->request->getParam('client', ''),
			);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['error' => 'That client was not found.'], Http::STATUS_NOT_FOUND);
		} catch (RuntimeException | InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('MailAddInController::contact failed', ['exception' => $e]);
			return new JSONResponse(['error' => 'The contact could not be added.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['object' => $created], Http::STATUS_CREATED);
	}//end contact()

	/**
	 * POST /api/mail-add-in/log: log the open mail as a contact moment on a
	 * contact or a client the caller may read.
	 *
	 * @return JSONResponse The contact moment (201), or a refusal.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-a-mail-can-be-logged-as-a-contact-moment-req-mai-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function log(): JSONResponse {
		[, $refusal] = $this->caller();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$logged = $this->openMail->logMail(
				entityType: (string)$this->request->getParam('entityType', ''),
				entityId: (string)$this->request->getParam('entityId', ''),
				subject: (string)$this->request->getParam('subject', ''),
				sentAt: (string)$this->request->getParam('sentAt', ''),
				preview: (string)$this->request->getParam('preview', ''),
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (OutOfBoundsException $e) {
			return new JSONResponse(['error' => 'That record was not found.'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('MailAddInController::log failed', ['exception' => $e]);
			return new JSONResponse(['error' => 'The mail could not be logged.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['contactMoment' => $logged], Http::STATUS_CREATED);
	}//end log()
}//end class
