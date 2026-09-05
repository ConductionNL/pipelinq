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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;
use InvalidArgumentException;
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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class CtiService {
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
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function handleWebhook(
		string $platform,
		array $payload,
		?string $rawBody = null,
		?string $signature = null,
	): array {
		$adapter = $this->adapterRegistry->get($platform);
		$rawForSig = ($rawBody ?? json_encode($payload));
		$rawForSigString = '';
		if ($rawForSig !== false) {
			$rawForSigString = (string)$rawForSig;
		}

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
		$valid = $adapter->verifyWebhookSignature($rawForSigString, ($signature ?? ''));

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
		try {
			$normalised = $adapter->handleInboundWebhook($payload);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI webhook: adapter could not parse the payload',
				['platform' => $platform, 'error' => $e->getMessage()]
			);
			throw new InvalidArgumentException('The webhook payload could not be parsed.', 0, $e);
		}
		$interactionId = null;
		$processingError = null;

		if ($valid === false) {
			$processingError = 'Invalid signature';
		}

		if ($valid === true) {
			try {
				$interactionId = $this->dispatchEvent(platform: $platform, event: $normalised);
			} catch (\Throwable $e) {
				$this->logger->error(
					'CTI webhook dispatch failed',
					['exception' => $e->getMessage(), 'platform' => $platform]
				);
				$processingError = $e->getMessage();
			}
		}

		$this->logEvent(
			platform: $platform,
			event: $normalised,
			signatureValid: $valid,
			processingError: $processingError,
		);

		return [
			'logged' => true,
			'valid' => $valid,
			'interactionId' => $interactionId,
		];
	}//end handleWebhook()

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
	 * @param string $direction inbound|outbound.
	 * @param string $fromNumber Caller number.
	 * @param string $toNumber Callee number.
	 * @param string $userId Acting agent UID.
	 * @param string $extension Agent extension.
	 * @param array<string,mixed> $extra Extra fields to merge in.
	 *
	 * @return string|null The new contactmoment ticket UUID or null on failure.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function createPendingContactmoment(
		string $direction,
		string $fromNumber,
		string $toNumber,
		string $userId,
		string $extension,
		array $extra = [],
	): ?string {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->warning('CTI: ticket register/schema not configured.');
			return null;
		}

		$fromE164 = $this->phoneNormaliser->normaliseForOrg($fromNumber)['e164'];
		$toE164 = $this->phoneNormaliser->normaliseForOrg($toNumber)['e164'];

		$payload = array_merge(
			[
				'channel' => 'telephony',
				'direction' => $direction,
				'from_number' => ($fromE164 ?? $fromNumber),
				'to_number' => ($toE164 ?? $toNumber),
				'cti_extension' => $extension,
				'assignee' => $userId,
				'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'title' => 'CTI ' . $direction . ' call',
			],
			$extra
		);

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
	}//end createPendingContactmoment()

	/**
	 * Update a contactmoment ticket with final metadata once the call ended.
	 *
	 * @param string $interactionId Contactmoment ticket UUID.
	 * @param int $durationSeconds Talk duration.
	 * @param string $outcome Disposition outcome (may be empty).
	 * @param string $dispositionSubject Disposition subject.
	 * @param string $dispositionNotes Disposition notes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	public function completeContactmoment(
		string $interactionId,
		int $durationSeconds,
		string $outcome,
		string $dispositionSubject,
		string $dispositionNotes,
	): void {
		if ($this->ticketService->isConfigured() === false) {
			return;
		}

		try {
			$dispositionOutcome = 'resolved';
			if ($outcome !== '') {
				$dispositionOutcome = $outcome;
			}

			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'duration_seconds' => $durationSeconds,
					'ended_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'disposition_subject' => $dispositionSubject,
					'disposition_outcome' => $dispositionOutcome,
					'disposition_notes' => $dispositionNotes,
				],
				uuid: $interactionId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI completeContactmoment failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
		}//end try
	}//end completeContactmoment()

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
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
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

		try {
			$this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: [
					'recording_url' => $recordingUrl,
					'recording_retention_expires_at' => $expiresAt,
				],
				uuid: $interactionId,
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI attachRecording failed',
				['exception' => $e->getMessage(), 'interactionId' => $interactionId]
			);
			return false;
		}
	}//end attachRecording()

	/**
	 * Originate an outbound call via the active adapter.
	 *
	 * @param string $userId Acting agent UID.
	 * @param string $extension Agent extension to ring.
	 * @param string $targetNumber Destination number (E.164 preferred).
	 *
	 * @return OriginateResult The outcome (success/failure, external call id, pre-created contactmoment id).
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
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
				direction: 'outbound',
				fromNumber: $callerId,
				toNumber: $targetNumber,
				userId: $userId,
				extension: $extension,
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAgentPresence_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'user_id' => $userId,
					],
					'limit' => 1,
				]
			);

			$uuid = null;
			if (is_array($existing) === true && count($existing) > 0) {
				$first = $existing[0];
				$uuid = $this->extractId(object: $first);
			}

			$objectService->saveObject(
				[
					'user_id' => $userId,
					'extension' => ($extension ?? ''),
					'presence_state' => $presenceState,
					'last_updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
					'platform' => ($platform ?? $this->activePlatform()),
				],
				[],
				$register,
				$schema,
				$uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI syncPresence failed',
				['exception' => $e->getMessage(), 'userId' => $userId]
			);
		}//end try
	}//end syncPresence()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAdapterConfig_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
			if (is_array($existing) === true && count($existing) > 0) {
				$first = $existing[0];
				return $this->toArray(object: $first);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI loadConfig failed',
				['exception' => $e->getMessage()]
			);
		}//end try

		return [];
	}//end loadConfig()

	/**
	 * Save the CTI singleton config (creating or updating).
	 *
	 * @param array<string,mixed> $config The config payload.
	 *
	 * @return array<string,mixed>
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
	 */
	public function saveConfig(array $config): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiAdapterConfig_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);

			$uuid = null;
			if (is_array($existing) === true && count($existing) > 0) {
				$uuid = $this->extractId(object: $existing[0]);
			}

			$saved = $objectService->saveObject($config, [], $register, $schema, $uuid);
			return $this->toArray(object: $saved);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CTI saveConfig failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end saveConfig()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$result = $objectService->findAll(
				[
					'filters' => array_merge(
						['register' => $register, 'schema' => $schema],
						array_filter(
							$filters,
							static fn ($v): bool => ($v !== null && $v !== '')
						)
					),
					'limit' => $limit,
					'offset' => $offset,
					'sort' => ['received_at' => 'desc'],
				]
			);

			if (is_array($result) === false) {
				return [];
			}

			return array_values(
				array_map(
					fn ($obj) => $this->toArray(object: $obj),
					$result
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI listEventLog failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end listEventLog()

	/**
	 * Purge event-log entries older than 30 days.
	 *
	 * @return int Number of deleted records.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over the fetch/filter/delete loop; extraction adds no clarity.
	 */
	public function purgeOldEventLog(): int {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
		if ($register === '' || $schema === '') {
			return 0;
		}

		$cutoff = gmdate('Y-m-d\TH:i:s\Z', (time() - (30 * 86400)));

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$oldEntries = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);

			if (is_array($oldEntries) === false) {
				return 0;
			}

			$deleted = 0;
			foreach ($oldEntries as $entry) {
				$data = $this->toArray(object: $entry);
				$receivedAt = (string)($data['received_at'] ?? '');
				if ($receivedAt === '' || $receivedAt >= $cutoff) {
					continue;
				}

				$id = $this->extractId(object: $entry);
				if ($id === null) {
					continue;
				}

				try {
					$objectService->deleteObject(register: $register, schema: $schema, uuid: $id);
					$deleted++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'CTI purgeOldEventLog: deleteObject failed',
						['exception' => $e->getMessage(), 'id' => $id]
					);
				}
			}//end foreach

			return $deleted;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CTI purgeOldEventLog failed',
				['exception' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end purgeOldEventLog()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'ctiEventLog_schema', '');
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
				direction: ($event->direction ?? 'inbound'),
				fromNumber: ($event->fromNumber ?? ''),
				toNumber: ($event->toNumber ?? ''),
				userId: ($event->userId ?? ''),
				extension: ($event->extension ?? ''),
				extra: $extra,
			);
		}

		$this->updateContactmoment(id: $existing, fields: $extra);
		return $existing;
	}//end upsertCallContactmoment()

	/**
	 * Finalise a contactmoment when a call ends.
	 *
	 * @param string $platform Adapter platform.
	 * @param CtiWebhookResult $event Normalised event.
	 *
	 * @return string|null Contactmoment UUID.
	 */
	private function finaliseContactmoment(string $platform, CtiWebhookResult $event): ?string {
		$id = $this->findContactmomentByCallId(externalCallId: $event->externalCallId);
		if ($id === null) {
			// Late ended-event with no prior ringing/answered: still create the record.
			$id = $this->createPendingContactmoment(
				direction: ($event->direction ?? 'inbound'),
				fromNumber: ($event->fromNumber ?? ''),
				toNumber: ($event->toNumber ?? ''),
				userId: ($event->userId ?? ''),
				extension: ($event->extension ?? ''),
				extra: [
					'telephony_platform' => $platform,
					'external_call_id' => $event->externalCallId,
				],
			);
		}

		if ($id === null) {
			return null;
		}

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

		$this->updateContactmoment(id: $id, fields: $fields);
		return $id;
	}//end finaliseContactmoment()

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
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
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
	 *
	 * @return array{outcome: string, interactionId: string, taskId: string|null}
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.1
	 */
	public function processDisposition(string $interactionId, string $subject, string $outcome, string $notes): array {
		return $this->dispositionService->processDisposition(
			interactionId: $interactionId,
			subject: $subject,
			outcome: $outcome,
			notes: $notes,
		);
	}//end processDisposition()

	/**
	 * Extract a UUID/id from an OR object or array.
	 *
	 * @param mixed $object OR entity, array, or serialised object.
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered id-extraction fallbacks (array/getter/jsonSerialize); flattening obscures precedence.
	 */
	private function extractId(mixed $object): ?string {
		if (is_array($object) === true) {
			$id = ($object['id'] ?? ($object['uuid'] ?? null));
			if ($id !== null) {
				return (string)$id;
			}

			return null;
		}

		if (is_object($object) === true) {
			foreach (['getUuid', 'getId'] as $method) {
				if (method_exists($object, $method) === true) {
					$value = $object->$method();
					if ($value !== null) {
						return (string)$value;
					}
				}
			}

			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					$id = ($serialised['id'] ?? ($serialised['uuid'] ?? null));
					if ($id !== null) {
						return (string)$id;
					}

					return null;
				}
			}
		}//end if

		return null;
	}//end extractId()

	/**
	 * Coerce an OR object to a plain associative array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$value = $object->jsonSerialize();
			if (is_array($value) === true) {
				return $value;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$value = $object->getObject();
			if (is_array($value) === true) {
				return $value;
			}
		}

		return [];
	}//end toArray()
}//end class
