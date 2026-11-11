<?php

/**
 * Pipelinq CtiController.
 *
 * Controller for the CTI screen-pop and click-to-dial API endpoints.
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CTI controller.
 *
 * Endpoints:
 *   POST  /api/cti/webhook/{platform}           PublicPage (signature verified)
 *   POST  /api/cti/screen-pop                   Authenticated user
 *   POST  /api/cti/click-to-dial                Authenticated user
 *   POST  /api/cti/contactmoment/{id}/disposition  Authenticated user
 *   POST  /api/cti/contactmoment/{id}/recording    Authenticated user
 *   GET   /api/cti/config                       Admin
 *   PUT   /api/cti/config                       Admin
 *   GET   /api/cti/test-connection              Admin
 *   GET   /api/cti/event-log                    Admin
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin controller wiring the
 *  CtiService surface to HTTP — coupling is unavoidable on a CRUD facade.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */
class CtiController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param CtiService $ctiService The CTI service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The logger.
	 * @param ConnectionReportService $connectionReports Reports the CTI check to integriq's connection registry.
	 */
	public function __construct(
		IRequest $request,
		private CtiService $ctiService,
		private CrmPrivilegeCheck $privilege,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private ConnectionReportService $connectionReports,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Inbound webhook handler.
	 *
	 * The route is PublicPage (the platform cannot CSRF-token sign);
	 * authenticity is enforced by the adapter's signature verification.
	 *
	 * The AnonRateLimit below sizes telephony platform events — one per call
	 * state change, so a busy contact centre generates these steadily.
	 *
	 * @param string $platform Platform identifier from the URL.
	 *
	 * @return JSONResponse Acknowledgement.
	 *
	 * Telephony platform events — one per call state change, so a busy contact
	 * centre generates these steadily and the rate-limit ceiling is generous.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function webhook(string $platform): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');
		$signature = (string)$this->request->getHeader('X-Pipelinq-Signature');
		if ($signature === '') {
			$signature = (string)$this->request->getHeader('Validation-Token');
		}

		if ($signature === '') {
			$signature = (string)($this->request->getParam('signature', '') ?? '');
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			$payload = (array)$this->request->getParams();
		}

		$signatureArg = null;
		if ($signature !== '') {
			$signatureArg = $signature;
		}

		try {
			$result = $this->ctiService->handleWebhook(
				platform: $platform,
				payload: $payload,
				rawBody: $rawBody,
				signature: $signatureArg,
			);
		} catch (\InvalidArgumentException $e) {
			// A body this adapter cannot parse. 400, not 5xx: a 5xx tells the
			// platform to redeliver a payload that will never parse, and this
			// route is public so the response must say nothing more than that.
			$this->logger->warning(
				'CTI webhook: unparseable payload',
				['platform' => $platform, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'The webhook payload could not be parsed'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\RuntimeException $e) {
			$this->logger->warning(
				'CTI webhook: unknown platform',
				['platform' => $platform, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($result['valid'] === false) {
			// 422 (Unprocessable Entity) signals an invalid webhook signature
			// without leaking session-level auth status to the telephony platform.
			return new JSONResponse(
				['error' => 'Invalid webhook signature', 'logged' => true],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'interactionId' => ($result['interactionId'] ?? null),
			]
		);
	}//end webhook()

	/**
	 * Trigger a screen-pop lookup.
	 *
	 * @return JSONResponse The screen-pop result.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function screenPop(): JSONResponse {
		// CTI surfaces resolve a caller's phone number to a customer record and
		// attach call recordings — CRM data, not an any-authenticated-user
		// capability. Admins bypass.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$fromNumber = (string)$this->request->getParam('fromNumber', '');
		if ($fromNumber === '') {
			return new JSONResponse(['error' => 'fromNumber is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->ctiService->initiateScreenPop($fromNumber);
		return new JSONResponse($result->toArray());
	}//end screenPop()

	/**
	 * Initiate an outbound click-to-dial.
	 *
	 * @return JSONResponse The originate result.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function clickToDial(): JSONResponse {
		// Placing an outbound call on the organisation's telephony account is a
		// CRM capability. Its sibling webhook() on this controller was guarded
		// in the first pass and clickToDial -- the one that actually DIALS --
		// was not.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		$targetNumber = (string)$this->request->getParam('targetNumber', '');
		$extension = (string)$this->request->getParam('extension', '');
		$refusal = (new CtiDialRules())->refusal(targetNumber: $targetNumber, extension: $extension);
		if ($refusal !== null) {
			return $refusal;
		}

		$result = $this->ctiService->originateCall(
			userId: $uid,
			extension: $extension,
			targetNumber: $targetNumber,
		);

		$status = Http::STATUS_BAD_GATEWAY;
		if ($result->success === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($result->toArray(), $status);
	}//end clickToDial()

	/**
	 * Submit a disposition form.
	 *
	 * @param string $id Contactmoment UUID.
	 *
	 * @return JSONResponse Outcome with optional task id.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function disposition(string $id): JSONResponse {
		// Same posture as screenPop()/attachRecording().
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$subject = (string)$this->request->getParam('subject', '');
		$outcome = (string)$this->request->getParam('outcome', '');
		$notes = (string)$this->request->getParam('notes', '');
		$queue = (string)$this->request->getParam('queue', '');

		if ($subject === '' || $outcome === '') {
			return new JSONResponse(
				['error' => 'subject and outcome are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->dispositionAnswer(
			process: fn (): array => $this->ctiService->processDisposition(
				interactionId: $id,
				subject: $subject,
				outcome: $outcome,
				notes: $notes,
				queue: $queue,
			)
		);
	}//end disposition()

	/**
	 * Run the disposition and answer its outcome, a 400 for a refused value or a logged 500.
	 *
	 * @param callable(): array<string, mixed> $process The disposition call.
	 *
	 * @return JSONResponse
	 */
	private function dispositionAnswer(callable $process): JSONResponse {
		try {
			$result = $process();
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('CTI disposition failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => 'Disposition processing failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result);
	}//end dispositionAnswer()

	/**
	 * Attach recording metadata to a contactmoment.
	 *
	 * Used by the adapter system to relay an ad-hoc recording event when the
	 * platform delivers the recording URL outside of the standard `ended`
	 * event.
	 *
	 * @param string $id Contactmoment UUID.
	 *
	 * @return JSONResponse Acknowledgement.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function attachRecording(string $id): JSONResponse {
		// CTI surfaces resolve a caller's phone number to a customer record and
		// attach call recordings — CRM data, not an any-authenticated-user
		// capability. Admins bypass.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$recordingUrl = (string)$this->request->getParam('recordingUrl', '');
		$expiresAt = (string)$this->request->getParam('expiresAt', '');
		if ($recordingUrl === '') {
			return new JSONResponse(['error' => 'recordingUrl is required'], Http::STATUS_BAD_REQUEST);
		}

		$attached = $this->ctiService->attachRecording($id, $recordingUrl, $expiresAt);
		if ($attached === false) {
			return new JSONResponse(
				['ok' => false, 'error' => 'The recording could not be attached'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['ok' => true]);
	}//end attachRecording()

	/**
	 * Read the current CTI singleton configuration.
	 *
	 * Admin-only, and now enforced at BOTH layers. The endpoint used to carry
	 * #[NoAdminRequired] so SecurityMiddleware would let a non-admin through to
	 * a body that then returned 403 itself. That is fail-closed but it declares
	 * the opposite of what it does, and the declaration is what a reviewer
	 * reads. The attribute is gone, so the framework rejects a non-admin before
	 * the controller runs; the isAdmin() check below stays as defence in depth.
	 *
	 * @auth admin-only Returns the instance-wide CTI platform configuration; the body additionally enforces it with an isAdmin() check.
	 *
	 * @return JSONResponse Current config (credentials never returned).
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	public function getConfig(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '' || $this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$config = $this->ctiService->loadConfig();
		// Strip secrets before returning.
		unset($config['webhook_secret']);
		return new JSONResponse($config);
	}//end getConfig()

	/**
	 * Update the CTI singleton configuration.
	 *
	 * Admin-only — see {@see self::getConfig()} for the auth model.
	 *
	 * A save that stored something runs the CTI check once and reports it to
	 * integriq, because the platform lives on an OpenRegister object integriq
	 * cannot read (adopt-connection-registry design D2). The check makes no
	 * network call, and the report never changes this response.
	 *
	 * @auth admin-only Writes the instance-wide CTI platform configuration and
	 *       credentials reference; the body additionally enforces it with an
	 *       isAdmin() check.
	 *
	 * @return JSONResponse The saved configuration.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function updateConfig(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '' || $this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$payload = $this->configPayload();
		$saved = $this->ctiService->saveConfig($payload);
		$this->logger->info(
			'CTI config updated',
			['user' => $uid, 'fields' => array_keys($payload)]
		);

		if ($saved !== []) {
			$this->connectionReports->reportCtiCheck(outcome: $this->ctiService->testConnection());
		}

		unset($saved['webhook_secret']);
		return new JSONResponse($saved);
	}//end updateConfig()

	/**
	 * The configuration fields the request carries, in the fixed field order; absent or null ones are left out.
	 *
	 * @return array<string, mixed>
	 */
	private function configPayload(): array {
		$allowedFields = [
			'platform',
			'api_base_url',
			'auth_method',
			'credentials_ref',
			'screen_pop_enabled',
			'screen_pop_delay_ms',
			'click_to_dial_enabled',
			'default_outbound_caller_id',
			'webhook_secret',
			'default_country_code',
		];

		$payload = [];
		foreach ($allowedFields as $field) {
			$value = $this->request->getParam($field, null);
			if ($value !== null) {
				$payload[$field] = $value;
			}
		}

		return $payload;
	}//end configPayload()

	/**
	 * Test platform connectivity (admin only).
	 *
	 * @auth admin-only Opens an outbound connection using the stored CTI credentials; the body additionally enforces it with an isAdmin() check.
	 *
	 * The outcome is also reported to integriq's connection registry for the
	 * `cti` row. The report never changes this response.
	 *
	 * @return JSONResponse Test outcome.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-132-pipelinq-reports-what-its-own-checks-observe
	 */
	public function testConnection(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '' || $this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->ctiService->testConnection();
		$this->connectionReports->reportCtiCheck(outcome: $outcome);

		return new JSONResponse($outcome);
	}//end testConnection()

	/**
	 * Read the CTI webhook event log (admin only).
	 *
	 * @auth admin-only Exposes the instance-wide CTI webhook event log; the body additionally enforces it with an isAdmin() check.
	 *
	 * @return JSONResponse Event log entries (max 30-day retention).
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
	 */
	public function eventLog(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '' || $this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$filters = array_filter(
			[
				'platform' => (string)$this->request->getParam('platform', ''),
				'event_type' => (string)$this->request->getParam('event_type', ''),
			]
		);

		$limit = (int)$this->request->getParam('limit', 50);
		$offset = (int)$this->request->getParam('offset', 0);
		$events = $this->ctiService->listEventLog($filters, $limit, $offset);

		return new JSONResponse(['events' => $events, 'limit' => $limit, 'offset' => $offset]);
	}//end eventLog()
}//end class
