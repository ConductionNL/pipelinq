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
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\OpenMailServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\MailAddIn\AddInManifest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	 * @param OpenMailServiceInterface $openMail Sender lookup, add as contact and mail logging.
	 * @param ObjectOwnerAccessPolicy $policy Who may create contacts.
	 * @param LoggerInterface $logger Logger.
	 * @param AddInManifest $addIn The add-in manifest and the pane's URLs.
	 * @param IInitialState $initialState Hands the pane the URLs it calls.
	 * @param IURLGenerator $urls Builds those URLs.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly OpenMailServiceInterface $openMail,
		private readonly ObjectOwnerAccessPolicy $policy,
		private readonly LoggerInterface $logger,
		private readonly AddInManifest $addIn,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /mail-add-in/manifest.xml: the Office add-in manifest, with this
	 * instance's URLs, for an administrator to deploy in Microsoft 365.
	 *
	 * Public, because the Microsoft 365 admin centre can fetch it by URL. It
	 * holds nothing but this instance's public addresses.
	 *
	 * @return DataDisplayResponse The manifest XML.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function manifest(): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$this->addIn->xml(),
			Http::STATUS_OK,
			['Content-Type' => 'application/xml; charset=utf-8']
		);
		$response->addHeader('Content-Disposition', 'inline; filename="pipelinq-outlook-add-in.xml"');
		return $response;
	}//end manifest()

	/**
	 * GET /mail-add-in/taskpane: the page Outlook opens beside a mail.
	 *
	 * Public, because Outlook frames it without a Nextcloud session; the
	 * pane signs in through Nextcloud's login flow and sends the app password
	 * with every call. The page may be framed by the Outlook hosts only, and
	 * may load Office.js from Microsoft; nothing else in Nextcloud's policy
	 * changes.
	 *
	 * @return TemplateResponse The pane.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function taskpane(): TemplateResponse {
		$app = AppIdentity::APP_ID;
		$this->initialState->provideInitialState(
			'mail-add-in',
			[
				'loginFlowUrl' => $this->urls->linkToRouteAbsolute('core.ClientFlowLoginV2.init'),
				'lookupUrl' => $this->urls->linkToRouteAbsolute($app . '.mailAddIn.lookup'),
				'contactUrl' => $this->urls->linkToRouteAbsolute($app . '.mailAddIn.contact'),
				'logUrl' => $this->urls->linkToRouteAbsolute($app . '.mailAddIn.log'),
				'appUrl' => $this->urls->linkToRouteAbsolute($app . '.dashboard.page', ['path' => '']),
			]
		);

		$response = new TemplateResponse($app, 'mail-add-in', [], TemplateResponse::RENDER_AS_BASE);

		$csp = new ContentSecurityPolicy();
		foreach (AddInManifest::FRAME_HOSTS as $host) {
			$csp->addAllowedFrameAncestorDomain($host);
		}

		$csp->addAllowedScriptDomain(AddInManifest::OFFICE_JS_HOST);
		$response->setContentSecurityPolicy($csp);

		return $response;
	}//end taskpane()

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
