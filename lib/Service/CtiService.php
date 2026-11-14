<?php

/**
 * Pipelinq CtiService.
 *
 * Core orchestration service for the CTI screen-pop and click-to-dial adapter.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use OCA\Pipelinq\BackgroundJob\CallTranscriptionJob;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CtiServiceInterface;
use OCA\Pipelinq\Service\CallReview\CallTranscriptionService;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CallAgent;
use OCA\Pipelinq\Service\Cti\CallCompletion;
use OCA\Pipelinq\Service\Cti\CallRecording;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\CtiWebhookDelivery;
use OCA\Pipelinq\Service\Cti\EventLogQuery;
use OCA\Pipelinq\Service\Cti\PendingCall;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Core CTI orchestration service.
 *
 * Routes inbound webhook events through the registered adapter, logs them to
 * `ctiEventLog`, creates/updates contactmoment records, drives the screen-pop
 * lookup and originates outbound calls.
 *
 * Since unify-ticket-supertype a contactmoment is a `ticket` carrying
 * `ticketType: contactmoment`; every contactmoment read/write here goes through
 * {@see TicketService}. The CTI-owned schemas (`ctiEventLog`,
 * `ctiAdapterConfig`, `ctiAgentPresence`) are unaffected and keep their own
 * app-config keys.
 *
 * All public mutating methods log to the event log; all OR writes use
 * `saveObject` (per Hydra OR ObjectService API).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Central orchestrator that wires
 *  contact matching, disposition, adapter framework and OR persistence together.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive CTI orchestrator; splitting would fragment a single responsibility.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Each method maps to one CTI event/operation; grouping keeps the flow readable.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public surface mirrors the CTI controller/webhook operations it backs.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregate of many small guard-clause methods; no single hotspot.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class CtiService implements CtiServiceInterface {
	use PayloadToArray;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for OR lookups.
	 * @param IAppConfig $appConfig The app config.
	 * @param AdapterRegistry $adapterRegistry Adapter registry.
	 * @param PhoneNormaliser $phoneNormaliser Phone normaliser.
	 * @param CtiContactMatcher $contactMatcher Contact matcher.
	 * @param CtiDispositionService $dispositionService Disposition service.
	 * @param TicketService $ticketService Unified ticket resolver.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private AdapterRegistry $adapterRegistry,
		private PhoneNormaliser $phoneNormaliser,
		private CtiContactMatcher $contactMatcher,
		private CtiDispositionService $dispositionService,
		private TicketService $ticketService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an inbound webhook payload.
	 *
	 * Persists the raw payload to the event log, then dispatches based on the
	 * normalised event type:
	 *   - ringing/answered → create/update a pending contactmoment
	 *   - ended            → write call duration and recording fields
	 *   - presence         → update ctiAgentPresence
	 *
	 * @param string $platform Platform identifier.
	 * @param array<string,mixed> $payload Decoded webhook body.
	 * @param string|null $rawBody Raw body (for signature recheck).
	 * @param string|null $signature Signature header from the request.
	 *
	 * @return array{logged: bool, valid: bool, interactionId: string|null}
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function handleWebhook(
		string $platform,
		array $payload,
		?string $rawBody = null,
		?string $signature = null,
	): array {
		$adapter = $this->adapterRegistry->get($platform);

		// 🔴 ALWAYS VERIFY, INCLUDING WHEN NO SIGNATURE ARRIVED.
		//
		// This used to start at `$valid = true` and verify only `if ($signature
		// !== null)`, so a delivery carrying no signature header at all was
		// treated as verified and dispatched. The route is #[PublicPage] and
		// #[NoCSRFRequired], which made that an UNAUTHENTICATED WRITE PRIMITIVE:
		// an anonymous POST wrote a contactmoment.
		//
		// A missing signature is not a delivery to trust, it is the one to
		// trust least. Every adapter already answers false for an empty
		// signature or an unconfigured secret, so passing '' through is the
		// correct fail-closed call rather than a special case here.
		//
		// OPERATIONAL NOTE: an instance that has a CTI webhook wired but no
		// `cti_*_webhook_secret` configured accepted unsigned deliveries before
		// and now rejects them with 422. That is the point of the change, and
		// the fix for such an instance is to configure the secret the adapter
		// already reads.
		$valid = $adapter->verifyWebhookSignature($this->signedBody(payload: $payload, rawBody: $rawBody), ($signature ?? ''));

		// 🔴 A MALFORMED BODY IS THE EXPECTED CASE ON A PUBLIC ROUTE.
		//
		// This call sat outside any try/catch, and the controller caught only
		// RuntimeException, so an adapter that could not parse the payload
		// (JsonException, TypeError, anything) escaped as an unhandled 500 on a
		// #[PublicPage] endpoint -- a stack trace's worth of signal to an
		// anonymous caller, and a 5xx that tells the platform to redeliver a
		// body that will never parse.
		//
		// Wrapped as InvalidArgumentException so the controller can answer 400:
		// the delivery is the caller's problem, not the server's.
		$event = $this->parseWebhook(adapter: $adapter, delivery: new CtiWebhookDelivery(platform: $platform, payload: $payload));

		$outcome = ['interactionId' => null, 'processingError' => 'Invalid signature'];
		if ($valid === true) {
			$outcome = $this->dispatchVerifiedEvent(platform: $platform, event: $event);
		}

		$this->logEvent(
			platform: $platform,
			event: $event,
			signatureValid: $valid,
			processingError: $outcome['processingError'],
		);

		return [
			'logged' => true,
			'valid' => $valid,
			'interactionId' => $outcome['interactionId'],
		];
	}//end handleWebhook()

	/**
	 * The body a webhook signature is checked against: the raw body when the
	 * controller has it, otherwise the re-encoded payload, or an empty string
	 * when that cannot be encoded.
	 *
	 * @param array<string,mixed> $payload Decoded webhook body.
	 * @param string|null         $rawBody Raw request body.
	 *
	 * @return string The signed body.
	 */
	private function signedBody(array $payload, ?string $rawBody): string {
		$rawForSig = ($rawBody ?? json_encode($payload));
		$rawForSigString = '';
		if ($rawForSig !== false) {
			$rawForSigString = (string)$rawForSig;
		}

		return $rawForSigString;
	}//end signedBody()

	/**
	 * Let the platform adapter normalise a delivery; a payload it cannot
	 * parse is logged and refused as the caller's problem.
	 *
	 * @param CtiAdapterInterface $adapter  The platform adapter.
	 * @param CtiWebhookDelivery  $delivery The platform and decoded body.
	 *
	 * @return CtiWebhookResult The normalised event.
	 *
	 * @throws InvalidArgumentException When the payload could not be parsed.
	 */
	private function parseWebhook(CtiAdapterInterface $adapter, CtiWebhookDelivery $delivery): CtiWebhookResult {
		try {
			return $adapter->handleInboundWebhook($delivery->payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI webhook: adapter could not parse the payload',
				['platform' => $delivery->platform, 'error' => $e->getMessage()]
			);
			throw new InvalidArgumentException('The webhook payload could not be parsed.', 0, $e);
		}
	}//end parseWebhook()

	/**
	 * Dispatch a verified event; a dispatch that fails is logged and its
	 * error recorded for the event log.
	 *
	 * @param string           $platform Platform identifier.
	 * @param CtiWebhookResult $event    The normalised event.
	 *
	 * @return array{interactionId: string|null, processingError: string|null} The outcome.
	 */
	private function dispatchVerifiedEvent(string $platform, CtiWebhookResult $event): array {
		try {
			return ['interactionId' => $this->dispatchEvent(platform: $platform, event: $event), 'processingError' => null];
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI webhook dispatch failed',
				['exception' => $e->getMessage(), 'platform' => $platform]
			);
			return ['interactionId' => null, 'processingError' => $e->getMessage()];
		}
	}//end dispatchVerifiedEvent()

	/**
	 * Dispatch a normalised event to the appropriate handler.
	 *
	 * @param string $platform Active platform identifier.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID when the event mutated one.
	 */
	private function dispatchEvent(string $platform, CtiWebhookResult $event): ?string {
		return match ($event->eventType) {
			'ringing', 'answered' => $this->upsertCallContactmoment(platform: $platform, event: $event),
			'ended' => $this->finaliseContactmoment(platform: $platform, event: $event),
			'abandoned' => $this->markAbandoned(platform: $platform, event: $event),
			'presence' => $this->syncPresenceFromEvent(platform: $platform, event: $event),
			'recording' => $this->attachRecordingFromEvent(platform: $platform, event: $event),
			default => null,
		};
	}//end dispatchEvent()

	/**
	 * Initiate a screen-pop lookup for an inbound call.
	 *
	 * @param string $fromNumber Raw caller phone number.
	 * @param string|null $orgId Reserved for multi-tenant pipelinq.
	 *
	 * @return ScreenPopResult The screen-pop result for the frontend.
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function initiateScreenPop(string $fromNumber, ?string $orgId = null): ScreenPopResult {
		$normalised = $this->phoneNormaliser->normaliseForOrg($fromNumber, $orgId);
		$result = $this->contactMatcher->findByPhoneNumber($normalised['e164'], $orgId);

		$action = ScreenPopResult::ACTION_INTAKE;
		if ($result['totalMatches'] === 1) {
			$action = ScreenPopResult::ACTION_NAVIGATE;
		} elseif ($result['totalMatches'] > 1) {
			$action = ScreenPopResult::ACTION_CHOOSER;
		}

		return new ScreenPopResult(
			action: $action,
			matches: $result['matches'],
			e164: $normalised['e164'],
			rawNumber: $normalised['raw'],
		);
	}//end initiateScreenPop()

	/**
	 * Create a pending contactmoment ticket.
	 *
	 * @param PendingCall         $call  The call: direction, numbers and agent.
	 * @param array<string,mixed> $extra Extra fields to merge in.
	 *
	 * @return string|null The new contactmoment ticket UUID or null on failure.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function createPendingContactmoment(PendingCall $call, array $extra = []): ?string {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI: ticket register/schema not configured.');
			return null;
		}

		return $this->savePendingContactmoment(
			payload: array_merge($this->pendingContactmomentFields(call: $call), $extra)
		);
	}//end createPendingContactmoment()

	/**
	 * The fields of a new pending contactmoment, with the numbers in E.164
	 * where they normalise and as given where they do not.
	 *
	 * @param PendingCall $call The call.
	 *
	 * @return array<string,mixed> The ticket fields.
	 */
	private function pendingContactmomentFields(PendingCall $call): array {
		$fromE164 = $this->phoneNormaliser->normaliseForOrg($call->fromNumber)['e164'];
		$toE164 = $this->phoneNormaliser->normaliseForOrg($call->toNumber)['e164'];

		return [
			'channel' => 'telephony',
			'direction' => $call->direction,
			'from_number' => ($fromE164 ?? $call->fromNumber),
			'to_number' => ($toE164 ?? $call->toNumber),
			'cti_extension' => $call->agent->extension,
			'assignee' => $call->agent->userId,
			'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'title' => 'CTI ' . $call->direction . ' call',
		];
	}//end pendingContactmomentFields()

	/**
	 * Save a new contactmoment ticket; a failed save is logged and gives null.
	 *
	 * @param array<string,mixed> $payload The ticket fields.
	 *
	 * @return string|null The new contactmoment ticket UUID or null on failure.
	 */
	private function savePendingContactmoment(array $payload): ?string {
		try {
			$saved = $this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $payload,
			);
			return $this->extractId(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI createPendingContactmoment failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end savePendingContactmoment()

	/**
	 * Update a contactmoment ticket with final metadata once the call ended.
	 *
	 * @param string         $interactionId Contactmoment ticket UUID.
	 * @param CallCompletion $completion    Talk duration and disposition (outcome, subject, notes).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function completeContactmoment(string $interactionId, CallCompletion $completion): void {
		if ($this->ticketService->isConfigured() === false) {
			return;
		}

		try {
			$this->writeCompletion(interactionId: $interactionId, completion: $completion);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI completeContactmoment failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
		}//end try
	}//end completeContactmoment()

	/**
	 * Write the final call metadata on a contactmoment ticket.
	 *
	 * @param string         $interactionId Contactmoment ticket UUID.
	 * @param CallCompletion $completion    Talk duration and disposition.
	 *
	 * @return void
	 */
	private function writeCompletion(string $interactionId, CallCompletion $completion): void {
		$this->ticketService->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: [
				'duration_seconds' => $completion->durationSeconds,
				'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'disposition_subject' => $completion->subject,
				'disposition_outcome' => $completion->dispositionOutcome(),
				'disposition_notes' => $completion->notes,
			],
			uuid: $interactionId,
		);
	}//end writeCompletion()

	/**
	 * Attach recording metadata to an existing contactmoment ticket.
	 *
	 * @param string $interactionId Contactmoment ticket UUID.
	 * @param string $recordingUrl URL of the recording.
	 * @param string $expiresAt ISO 8601 retention expiry.
	 *
	 * @return bool True when the recording was written, false when the ticket
	 *              store is unconfigured or the write failed.
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function attachRecording(string $interactionId, string $recordingUrl, string $expiresAt): bool {
		// 🔴 RETURNS WHETHER THE WRITE HAPPENED.
		//
		// This was `: void`, and swallowed BOTH the unconfigured-store early
		// return and every Throwable — so the controller had nothing to read
		// and answered 200 {ok: true} over a write that never occurred. A
		// recording URL is the evidence of a recorded call; being told it was
		// attached when it was not is worse than being told it failed.
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning(
				'CTI attachRecording: ticket store is not configured',
				['interactionId' => $interactionId]
			);
			return false;
		}

		$saved = $this->saveRecording(
			interactionId: $interactionId,
			recording: new CallRecording(recordingUrl: $recordingUrl, expiresAt: $expiresAt)
		);
		if ($saved === true) {
			$this->queueTranscription(interactionId: $interactionId);
		}

		return $saved;
	}//end attachRecording()

	/**
	 * Queue the transcription of a call whose recording was just attached,
	 * when an administrator has switched it on (contact-moments-call-review D1).
	 * Queuing never fails the attach: the recording is already stored.
	 *
	 * @param string $interactionId Contactmoment ticket UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.2
	 */
	private function queueTranscription(string $interactionId): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, CallTranscriptionService::ENABLED_KEY, 'false') !== 'true') {
			return;
		}

		try {
			$this->container->get(IJobList::class)->add(CallTranscriptionJob::class, ['ticketId' => $interactionId]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI attachRecording: the transcription could not be queued',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
		}
	}//end queueTranscription()

	/**
	 * Write a recording on a contactmoment ticket; a failed write is logged.
	 *
	 * @param string        $interactionId Contactmoment ticket UUID.
	 * @param CallRecording $recording     The recording URL and its retention expiry.
	 *
	 * @return bool True when the recording was written.
	 */
	private function saveRecording(string $interactionId, CallRecording $recording): bool {
		try {
			$this->writeRecording(interactionId: $interactionId, recording: $recording);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI attachRecording failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
			return false;
		}
	}//end saveRecording()

	/**
	 * Write the recording fields on a contactmoment ticket.
	 *
	 * @param string        $interactionId Contactmoment ticket UUID.
	 * @param CallRecording $recording     The recording URL and its retention expiry.
	 *
	 * @return void
	 */
	private function writeRecording(string $interactionId, CallRecording $recording): void {
		$this->ticketService->save(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			payload: [
				'recording_url' => $recording->recordingUrl,
				'recording_retention_expires_at' => $recording->expiresAt,
			],
			uuid: $interactionId,
		);
	}//end writeRecording()

	/**
	 * Originate an outbound call via the active adapter.
	 *
	 * @param string $userId Acting agent UID.
	 * @param string $extension Agent extension to ring.
	 * @param string $targetNumber Destination number (E.164 preferred).
	 *
	 * @return OriginateResult The outcome (success/failure, external call id, pre-created contactmoment id).
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function originateCall(string $userId, string $extension, string $targetNumber): OriginateResult {
		$platform = $this->activePlatform();
		if ($platform === '') {
			return new OriginateResult(
				success: false,
				error: 'No CTI platform configured.',
			);
		}

		$config = $this->loadConfig();
		$callerId = (string)($config['default_outbound_caller_id'] ?? '');

		try {
			$adapter = $this->adapterRegistry->get($platform);
			$callResult = $adapter->originateCall(
				extension: $extension,
				targetNumber: $targetNumber,
				callerId: $callerId,
			);
		} catch (\Throwable $e) {
			return new OriginateResult(
				success: false,
				error: 'CTI adapter error: ' . $e->getMessage(),
				platform: $platform,
			);
		}

		$interactionId = null;
		if ($callResult->success === true) {
			$interactionId = $this->createPendingContactmoment(
				call: new PendingCall(
					direction: 'outbound',
					fromNumber: $callerId,
					toNumber: $targetNumber,
					agent: new CallAgent(userId: $userId, extension: $extension),
				),
				extra: [
					'telephony_platform' => $platform,
					'external_call_id' => ($callResult->externalCallId ?? ''),
				],
			);
		}

		return new OriginateResult(
			success: $callResult->success,
			externalCallId: $callResult->externalCallId,
			interactionId: $interactionId,
			error: $callResult->error,
			platform: $platform,
		);
	}//end originateCall()

	/**
	 * Sync presence state for an agent (single source of truth).
	 *
	 * @param string $userId NC user UID.
	 * @param string $presenceState One of available|on-call|wrap-up|away|offline.
	 * @param string|null $extension Agent extension.
	 * @param string|null $platform Reporting platform.
	 *
	 * @return void
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function syncPresence(string $userId, string $presenceState, ?string $extension = null, ?string $platform = null): void {
		$target = $this->schemaTarget(schemaKey: 'ctiAgentPresence_schema');
		if ($target === null) {
			return;
		}

		try {
			[$register, $schema] = $target;
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$uuid = $this->firstId(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema, 'user_id' => $userId]);
			$payload = $this->presencePayload(userId: $userId, presenceState: $presenceState, extension: $extension, platform: $platform);
			$objectService->saveObject($payload, [], $register, $schema, $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning('CTI syncPresence failed', ['exception' => $e->getMessage(), 'userId' => $userId]);
		}//end try
	}//end syncPresence()

	/**
	 * The agent presence record to store.
	 *
	 * @param string $userId NC user UID.
	 * @param string $presenceState The presence state.
	 * @param string|null $extension Agent extension.
	 * @param string|null $platform Reporting platform; the active platform when null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function presencePayload(string $userId, string $presenceState, string|null $extension, string|null $platform): array {
		return [
			'user_id' => $userId,
			'extension' => ($extension ?? ''),
			'presence_state' => $presenceState,
			'last_updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'platform' => ($platform ?? $this->activePlatform()),
		];
	}//end presencePayload()

	/**
	 * The configured register and the schema stored under a config key, or null when either is unset.
	 *
	 * @param string $schemaKey The app config key of the schema.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function schemaTarget(string $schemaKey): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return [$register, $schema];
	}//end schemaTarget()

	/**
	 * Run ObjectService::findAll with the given filters and limit.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string, mixed> $filters The filters.
	 * @param int $limit Maximum number of objects.
	 *
	 * @return mixed The findAll result.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function findObjects(object $objectService, array $filters, int $limit) {
		return $objectService->findAll(
			[
				'filters' => $filters,
				'limit' => $limit,
			]
		);
	}//end findObjects()

	/**
	 * The first object matching the filters, as a list of zero or one element.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function firstObject(object $objectService, array $filters): array {
		$existing = $this->findObjects(objectService: $objectService, filters: $filters, limit: 1);
		if (is_array($existing) === true && count($existing) > 0) {
			return [$existing[0]];
		}

		return [];
	}//end firstObject()

	/**
	 * The id of the first object matching the filters, or null when there is none.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function firstId(object $objectService, array $filters): string|null {
		$found = $this->firstObject(objectService: $objectService, filters: $filters);
		if ($found === []) {
			return null;
		}

		return $this->extractId(object: $found[0]);
	}//end firstId()

	/**
	 * Active CTI platform from configuration.
	 *
	 * @return string Platform identifier (empty when not configured).
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function activePlatform(): string {
		$config = $this->loadConfig();
		return (string)($config['platform'] ?? '');
	}//end activePlatform()

	/**
	 * Read the CTI singleton config.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function loadConfig(): array {
		$target = $this->schemaTarget(schemaKey: 'ctiAdapterConfig_schema');
		if ($target === null) {
			return [];
		}

		try {
			return $this->configRecord(target: $target);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI loadConfig failed',
				['exception' => $e->getMessage()]
			);
		}//end try

		return [];
	}//end loadConfig()

	/**
	 * The stored CTI config record, or an empty array when none exists.
	 *
	 * @param array{0: string, 1: string} $target The register and config schema.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function configRecord(array $target): array {
		[$register, $schema] = $target;
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$found = $this->firstObject(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema]);
		if ($found === []) {
			return [];
		}

		return $this->toArray(value: $found[0]);
	}//end configRecord()

	/**
	 * Save the CTI singleton config (creating or updating).
	 *
	 * @param array<string,mixed> $config The config payload.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function saveConfig(array $config): array {
		$target = $this->schemaTarget(schemaKey: 'ctiAdapterConfig_schema');
		if ($target === null) {
			return [];
		}

		try {
			return $this->writeConfig(target: $target, config: $config);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI saveConfig failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end saveConfig()

	/**
	 * Create or update the CTI config record.
	 *
	 * @param array{0: string, 1: string} $target The register and config schema.
	 * @param array<string, mixed> $config The config payload.
	 *
	 * @return array<string, mixed> The saved record.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	private function writeConfig(array $target, array $config): array {
		[$register, $schema] = $target;
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$uuid = $this->firstId(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema]);

		$saved = $objectService->saveObject($config, [], $register, $schema, $uuid);
		return $this->toArray(value: $saved);
	}//end writeConfig()

	/**
	 * Test connectivity against the configured platform.
	 *
	 * @return array{ok: bool, platform: string, message: string}
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function testConnection(): array {
		$platform = $this->activePlatform();
		if ($platform === '') {
			return [
				'ok' => false,
				'platform' => '',
				'message' => 'No CTI platform configured.',
			];
		}

		try {
			$this->adapterRegistry->get($platform);
			return [
				'ok' => true,
				'platform' => $platform,
				'message' => 'Adapter resolved and configuration present.',
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'platform' => $platform,
				'message' => $e->getMessage(),
			];
		}
	}//end testConnection()

	/**
	 * List event-log entries with optional filters (max 30 days back).
	 *
	 * @param array<string,mixed> $filters Filter map: platform, event_type, since, until.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<int,array<string,mixed>>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function listEventLog(array $filters = [], int $limit = 50, int $offset = 0): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$query = new EventLogQuery(filters: $filters, limit: $limit, offset: $offset);
		return $this->readEventLog(config: $query->config(register: $register, schema: $schema));
	}//end listEventLog()

	/**
	 * Read event log rows as plain arrays; a failed read is logged and a
	 * non-list answer gives none.
	 *
	 * @param array<string,mixed> $config The findAll config.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function readEventLog(array $config): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			return $this->rowsToArrays(rows: $objectService->findAll($config));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI listEventLog failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end readEventLog()

	/**
	 * Event log rows as a list of plain arrays; a non-list answer gives none.
	 *
	 * @param mixed $rows What findAll answered.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsToArrays(mixed $rows): array {
		if (is_array($rows) === false) {
			return [];
		}

		return array_values(
			array_map(
				fn ($obj) => $this->toArray(value: $obj),
				$rows
			)
		);
	}//end rowsToArrays()

	/**
	 * Purge event-log entries older than 30 days.
	 *
	 * @return int Number of deleted records.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function purgeOldEventLog(): int {
		$target = $this->schemaTarget(schemaKey: 'ctiEventLog_schema');
		if ($target === null) {
			return 0;
		}

		$cutoff = gmdate('Y-m-d\TH:i:s\Z', (time() - (30 * 86400)));

		try {
			return $this->purgeBefore(target: $target, cutoff: $cutoff);
		} catch (\Throwable $e) {
			$this->logger->warning('CTI purgeOldEventLog failed', ['exception' => $e->getMessage()]);
			return 0;
		}//end try
	}//end purgeOldEventLog()

	/**
	 * Delete the event-log entries received before the cutoff.
	 *
	 * @param array{0: string, 1: string} $target The register and event-log schema.
	 * @param string $cutoff The ISO-8601 cutoff.
	 *
	 * @return int Number of deleted records.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	private function purgeBefore(array $target, string $cutoff): int {
		[$register, $schema] = $target;
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$oldEntries = $this->findObjects(objectService: $objectService, filters: ['register' => $register, 'schema' => $schema], limit: 1000);

		if (is_array($oldEntries) === false) {
			return 0;
		}

		$deleted = 0;
		foreach ($oldEntries as $entry) {
			if ($this->purgeEntry(objectService: $objectService, entry: $entry, target: $target, cutoff: $cutoff) === true) {
				$deleted++;
			}
		}

		return $deleted;
	}//end purgeBefore()

	/**
	 * Delete one event-log entry when it was received before the cutoff.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param mixed $entry The event-log entry.
	 * @param array{0: string, 1: string} $target The register and event-log schema.
	 * @param string $cutoff The ISO-8601 cutoff.
	 *
	 * @return bool Whether the entry was deleted.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	private function purgeEntry(object $objectService, $entry, array $target, string $cutoff): bool {
		$data = $this->toArray(value: $entry);
		$receivedAt = (string)($data['received_at'] ?? '');
		if ($receivedAt === '' || $receivedAt >= $cutoff) {
			return false;
		}

		$id = $this->extractId(object: $entry);
		if ($id === null) {
			return false;
		}

		return $this->deleteLogEntry(objectService: $objectService, target: $target, id: $id);
	}//end purgeEntry()

	/**
	 * Delete one event-log entry, logging a failure.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param array{0: string, 1: string} $target The register and event-log schema.
	 * @param string $id The entry id.
	 *
	 * @return bool Whether the entry was deleted.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	private function deleteLogEntry(object $objectService, array $target, string $id): bool {
		[$register, $schema] = $target;
		try {
			$objectService->deleteObject(register: $register, schema: $schema, uuid: $id);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI purgeOldEventLog: deleteObject failed',
				['exception' => $e->getMessage(), 'id' => $id]
			);
			return false;
		}
	}//end deleteLogEntry()

	/**
	 * Persist a webhook event in the CTI event log.
	 *
	 * @param string $platform Platform identifier.
	 * @param CtiWebhookResult $event Normalised event.
	 * @param bool $signatureValid Whether signature validation passed.
	 * @param string|null $processingError Error captured during processing.
	 *
	 * @return void
	 */
	private function logEvent(
		string $platform,
		CtiWebhookResult $event,
		bool $signatureValid,
		?string $processingError,
	): void {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$objectService->saveObject(
				[
					'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'platform' => $platform,
					'event_type' => $event->eventType,
					'external_call_id' => $event->externalCallId,
					'payload_json' => $event->raw,
					'processed_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'processing_error' => $processingError,
					'signature_valid' => $signatureValid,
				],
				[],
				$register,
				$schema,
				null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI logEvent failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end logEvent()

	/**
	 * Create or update the contactmoment on a ringing/answered event.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 */
	private function upsertCallContactmoment(string $platform, CtiWebhookResult $event): ?string {
		$existing = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		$extra = [
			'telephony_platform' => $platform,
			'external_call_id' => $event->externalCallId,
			'queue_name' => ($event->queueName ?? ''),
			'agent_skill' => ($event->agentSkill ?? ''),
		];
		if ($event->eventType === 'answered') {
			$extra['answered_at'] = gmdate('Y-m-d\TH:i:s\Z');
		}

		if ($existing === null) {
			return $this->createPendingContactmoment(
				call: $this->pendingCallFor(event: $event),
				extra: $extra,
			);
		}

		$this->updateContactmoment(id: $existing, fields: $extra);
		return $existing;
	}//end upsertCallContactmoment()

	/**
	 * The pending call a webhook event describes; an event without a
	 * direction is an inbound call, missing numbers and agent are empty.
	 *
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return PendingCall The call.
	 */
	private function pendingCallFor(CtiWebhookResult $event): PendingCall {
		return new PendingCall(
			direction: ($event->direction ?? 'inbound'),
			fromNumber: ($event->fromNumber ?? ''),
			toNumber: ($event->toNumber ?? ''),
			agent: new CallAgent(userId: ($event->userId ?? ''), extension: ($event->extension ?? '')),
		);
	}//end pendingCallFor()

	/**
	 * Finalise a contactmoment when a call ends.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 */
	private function finaliseContactmoment(string $platform, CtiWebhookResult $event): ?string {
		$id = $this->contactmomentIdFor(platform: $platform, event: $event);
		if ($id === null) {
			return null;
		}

		$this->updateContactmoment(id: $id, fields: $this->endedFields(event: $event));
		return $id;
	}//end finaliseContactmoment()

	/**
	 * The contactmoment of an ended call, created when no earlier event made one.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 */
	private function contactmomentIdFor(string $platform, CtiWebhookResult $event): string|null {
		$id = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		if ($id === null) {
			// Late ended-event with no prior ringing/answered: still create the record.
			$id = $this->createPendingContactmoment(
				call: $this->pendingCallFor(event: $event),
				extra: [
					'telephony_platform' => $platform,
					'external_call_id' => $event->externalCallId,
				],
			);
		}

		return $id;
	}//end contactmomentIdFor()

	/**
	 * The fields an ended call writes on its contactmoment.
	 *
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return array<string, mixed>
	 */
	private function endedFields(CtiWebhookResult $event): array {
		$fields = [
			'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'duration_seconds' => ($event->durationSeconds ?? 0),
		];
		if ($event->recordingUrl !== null) {
			$fields['recording_url'] = $event->recordingUrl;
		}

		if ($event->recordingExpiresAt !== null) {
			$fields['recording_retention_expires_at'] = $event->recordingExpiresAt;
		}

		return $fields;
	}//end endedFields()

	/**
	 * Mark a contactmoment as abandoned (caller hung up before answer).
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 */
	private function markAbandoned(string $platform, CtiWebhookResult $event): ?string {
		$id = $this->upsertCallContactmoment(platform: $platform, event: $event);
		if ($id === null) {
			return null;
		}

		$this->updateContactmoment(
			id: $id,
			fields: [
				'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'duration_seconds' => 0,
				'disposition_outcome' => 'abandoned',
			]
		);
		return $id;
	}//end markAbandoned()

	/**
	 * Sync presence from a normalised webhook event.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return null Always null (presence does not own a contactmoment).
	 */
	private function syncPresenceFromEvent(string $platform, CtiWebhookResult $event): null {
		if ($event->userId === null && $event->extension === null) {
			return null;
		}

		$this->syncPresence(
			userId: ($event->userId ?? ($event->extension ?? '')),
			presenceState: ($event->presenceState ?? 'available'),
			extension: $event->extension,
			platform: $platform,
		);
		return null;
	}//end syncPresenceFromEvent()

	/**
	 * Attach recording metadata from a normalised recording event.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $platform is part of the shared dispatchEvent() handler signature.
	 */
	private function attachRecordingFromEvent(string $platform, CtiWebhookResult $event): ?string {
		if ($event->recordingUrl === null) {
			return null;
		}

		$id = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		if ($id === null) {
			return null;
		}

		$this->attachRecording(
			interactionId: $id,
			recordingUrl: $event->recordingUrl,
			expiresAt: ($event->recordingExpiresAt ?? ''),
		);
		return $id;
	}//end attachRecordingFromEvent()

	/**
	 * Resolve a contactmoment ticket by external call ID.
	 *
	 * @param string $externalCallId Platform call UUID.
	 *
	 * @return string|null Contactmoment ticket UUID, null when none.
	 */
	private function findContactmomentByCallId(string $externalCallId): ?string {
		if ($externalCallId === '') {
			return null;
		}

		$found = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_CONTACTMOMENT,
			extraFilters: ['external_call_id' => $externalCallId],
			limit: 1,
		);

		if (count($found) > 0) {
			return $this->extractId(object: $found[0]);
		}

		return null;
	}//end findContactmomentByCallId()

	/**
	 * Save partial fields onto an existing contactmoment ticket.
	 *
	 * @param string $id Contactmoment ticket UUID.
	 * @param array<string,mixed> $fields Fields to merge.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	private function updateContactmoment(string $id, array $fields): void {
		if ($this->ticketService->isConfigured() === false) {
			return;
		}

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $fields,
				uuid: $id,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI updateContactmoment failed',
				['exception' => $e->getMessage(), 'id' => $id]
			);
		}
	}//end updateContactmoment()

	/**
	 * Process an agent's disposition form submission.
	 *
	 * Delegates to {@see CtiDispositionService}.
	 *
	 * @param string $interactionId Contactmoment UUID.
	 * @param string $subject Disposition subject.
	 * @param string $outcome Outcome enum.
	 * @param string $notes Notes.
	 * @param string $queue The escalation queue id, or ''.
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function processDisposition(string $interactionId, string $subject, string $outcome, string $notes, string $queue=''): array {
		return $this->dispositionService->processDisposition(
			interactionId: $interactionId,
			subject: $subject,
			outcome: $outcome,
			notes: $notes,
			queue: $queue,
		);
	}//end processDisposition()

	/**
	 * The escalation queues an agent can choose at wrap-up, by id and label.
	 *
	 * @return array<int, array{id: string, label: string}> The queues.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function escalationQueues(): array {
		return $this->dispositionService->queueChoices();
	}//end escalationQueues()

	/**
	 * Extract a UUID/id from an OR object or array.
	 *
	 * @param mixed $object OR entity, array, or serialised object.
	 *
	 * @return string|null
	 */
	private function extractId(mixed $object): ?string {
		if (is_array($object) === true) {
			return $this->arrayId(data: $object);
		}

		if (is_object($object) === true) {
			return $this->objectId(object: $object);
		}

		return null;
	}//end extractId()

	/**
	 * The `id`, else the `uuid`, of an array record.
	 *
	 * @param array<mixed> $data The record.
	 *
	 * @return string|null
	 */
	private function arrayId(array $data): string|null {
		$id = ($data['id'] ?? ($data['uuid'] ?? null));
		if ($id !== null) {
			return (string)$id;
		}

		return null;
	}//end arrayId()

	/**
	 * The id of an object: from its getters first, else from its serialised form.
	 *
	 * @param object $object The OR entity or object.
	 *
	 * @return string|null
	 */
	private function objectId(object $object): string|null {
		$id = $this->getterId(object: $object);
		if ($id !== null) {
			return $id;
		}

		return $this->serialisedId(object: $object);
	}//end objectId()

	/**
	 * The first non-null value of getUuid() or getId(), when the object has them.
	 *
	 * @param object $object The OR entity or object.
	 *
	 * @return string|null
	 */
	private function getterId(object $object): string|null {
		foreach (['getUuid', 'getId'] as $method) {
			if (method_exists($object, $method) === true) {
				$value = $object->$method();
				if ($value !== null) {
					return (string)$value;
				}
			}
		}

		return null;
	}//end getterId()

	/**
	 * The id of an object's jsonSerialize() array, when it serialises to one.
	 *
	 * @param object $object The OR entity or object.
	 *
	 * @return string|null
	 */
	private function serialisedId(object $object): string|null {
		if (method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $this->arrayId(data: $serialised);
			}
		}

		return null;
	}//end serialisedId()
}//end class
