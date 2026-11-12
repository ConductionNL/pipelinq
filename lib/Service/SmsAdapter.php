<?php

/**
 * Pipelinq SmsAdapter.
 *
 * Multi-provider SMS adapter. Resolves the right provider via
 * priority order (lower priority value = tried first), fails over to
 * the next provider on transient (5xx / network) errors, gates every
 * send through ConsentService + BudgetService, persists the outbound
 * row, and routes inbound webhook events to the conversation.
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsInbound;
use OCA\Pipelinq\Service\Provider\SmsMessage;
use OCA\Pipelinq\Service\Provider\SmsProviderAttempt;
use OCA\Pipelinq\Service\Provider\SmsSendFailure;
use OCA\Pipelinq\Service\Provider\SmsSendRun;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SmsAdapter — vendor-neutral outbound + inbound SMS surface.
 *
 * Public entry points:
 * - send(contact, body, providerHint?) — gated, failover-aware
 *   send returning an outcome envelope.
 * - handleInboundWebhook(rawBody, signature, providerId) —
 *   signature-verified webhook ingestion.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires consent +
 * budget + provider + repository + notifications + OR.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Vendor-neutral send/receive
 * surface with failover, persistence and webhook ingestion kept cohesive.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsAdapter {
	use PayloadToArray;
	use WritesAsMessagingAccount;

	/**
	 * Scope for the webhook's LOOKUPS only: reads without RBAC. Writes run as the messaging account.
	 *
	 * @var array<string, bool>
	 */
	private const LOOKUP_SCOPE = ['_rbac' => false, '_multitenancy' => false];

	/**
	 * Default pipelinq register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default message schema slug.
	 */
	private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'channelMessage';

	/**
	 * Default conversation schema slug.
	 */
	private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'channelConversation';

	/**
	 * Default contact schema slug (in the pipelinq register; the
	 * client-management register may also expose it under a
	 * different namespace).
	 */
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * Outcome label — sent successfully.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * Outcome label — refused by consent service (403).
	 */
	public const STATUS_CONSENT_MISSING = 'consentMissing';

	/**
	 * Outcome label — refused by budget service (403).
	 */
	public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';

	/**
	 * Outcome label — no active provider matches.
	 */
	public const STATUS_NO_PROVIDER = 'noProviderAvailable';

	/**
	 * Outcome label — provider call failed (all failover exhausted).
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param ChannelProviderRepository $providerRepo Provider read-side.
	 * @param SmsProviderFactory $providerFactory Vendor client factory.
	 * @param ConsentService $consentService Opt-out gate.
	 * @param BudgetService $budgetService Budget gate.
	 * @param NotificationService $notificationService Admin notifications.
	 * @param LoggerInterface $logger Logger.
	 * @param PhoneNormaliser $phoneNormaliser Normalises an inbound sender to E.164.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private SmsProviderFactory $providerFactory,
		private ConsentService $consentService,
		private BudgetService $budgetService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private PhoneNormaliser $phoneNormaliser,
	) {
	}//end __construct()

	/**
	 * Send an SMS, honouring consent, budget, and provider failover.
	 *
	 * @param array<string, mixed> $contact Contact row (must
	 *                                      expose `id` and a
	 *                                      phone number).
	 * @param string $body Message body.
	 * @param string|null $providerHint Pin a specific vendor
	 *                                  (caller-pinned, no
	 *                                  failover).
	 * @param array<string, mixed> $context Optional send context for
	 *                                      the outbound audit
	 *                                      (`clientId`, `agent`).
	 *
	 * @return array{
	 *     status: string,
	 *     messageId?: string,
	 *     externalMessageId?: string,
	 *     providerId?: string,
	 *     vendor?: string,
	 *     error?: string
	 * } Outcome envelope.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-006-outbound-sends-audited-as-contactmomenten
	 */
	public function send(array $contact, string $body, ?string $providerHint = null, array $context = []): array {
		$contactId = $this->extractId(payload: $contact);
		$toNumber = $this->extractPhone(contact: $contact);

		if ($toNumber === '') {
			return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
		}

		// An answer to the contact's own SMS goes to integriq as a reply, so an
		// opt-out does not stop it (hydra decision 2). Only an inbound SMS of
		// this contact counts; anything else is a message pipelinq starts.
		$inReplyTo = $this->verifiedInbound(contactId: $contactId, messageId: (string)($context['inReplyTo'] ?? ''));
		if ($this->consentService->canSend(contactId: $contactId, channel: 'sms', address: $toNumber, inReplyTo: $inReplyTo) === false) {
			return ['status' => self::STATUS_CONSENT_MISSING];
		}

		$tenantId = $this->getTenantId();
		$ordered = $this->orderedProvidersForSend(providerHint: $providerHint);
		if ($ordered === []) {
			return ['status' => self::STATUS_NO_PROVIDER];
		}

		$run = new SmsSendRun(
			message: new SmsMessage(contactId: $contactId, toNumber: $toNumber, body: $body, context: $context),
			tenantId: $tenantId,
			allowFailover: ($providerHint === null),
		);

		foreach ($ordered as $row) {
			$outcome = $this->attemptProviderSend(row: $row, run: $run);
			if ($outcome !== null) {
				return $outcome;
			}
		}

		// Exhausted all providers transiently — persist failed row + alert.
		return $this->persistFailureAndAlert(
			failure: new SmsSendFailure(
				message: $run->message,
				providerId: '',
				error: 'all SMS providers returned transient errors',
			),
		);
	}//end send()

	/**
	 * Try sending via a single provider row.
	 *
	 * Returns the final outcome envelope when the caller should stop
	 * (sent, budget-exceeded-without-failover, or a persisted failure
	 * that exhausts failover), or null when the caller should move on
	 * to the next provider in the ordered list.
	 *
	 * @param array<string, mixed> $row Provider row.
	 * @param SmsSendRun           $run The send this attempt belongs to.
	 *
	 * @return array<string, mixed>|null Outcome envelope, or null to continue.
	 */
	private function attemptProviderSend(array $row, SmsSendRun $run): ?array {
		$providerId = $this->extractId(payload: $row);

		if ($this->budgetService->canSend(tenantId: $run->tenantId, providerId: $providerId) === false) {
			if ($run->allowFailover === false) {
				return ['status' => self::STATUS_BUDGET_EXCEEDED, 'providerId' => $providerId];
			}

			return null;
		}

		$client = $this->providerFactory->create(channelProvider: $row);
		if ($client === null) {
			return null;
		}

		return $this->sendThrough(attempt: new SmsProviderAttempt(run: $run, providerId: $providerId, client: $client));
	}//end attemptProviderSend()

	/**
	 * Send through the attempt's client and settle the outcome.
	 *
	 * @param SmsProviderAttempt $attempt The provider being tried.
	 *
	 * @return array<string, mixed>|null Outcome envelope, or null to continue.
	 */
	private function sendThrough(SmsProviderAttempt $attempt): ?array {
		try {
			$result = $attempt->client->send(toNumber: $attempt->run->message->toNumber, body: $attempt->run->message->body);
		} catch (Throwable $e) {
			return $this->failedAttempt(attempt: $attempt, error: $e);
		}

		return $this->recordSent(attempt: $attempt, result: $result);
	}//end sendThrough()

	/**
	 * Log a provider failure; persist it and alert unless the send may fail over.
	 *
	 * A permanent failure always stops the send. A transient or unexpected
	 * one moves on to the next provider when failover is allowed.
	 *
	 * @param SmsProviderAttempt $attempt The attempt that failed.
	 * @param Throwable          $error   What the client threw.
	 *
	 * @return array<string, mixed>|null Failure envelope, or null to continue.
	 */
	private function failedAttempt(SmsProviderAttempt $attempt, Throwable $error): ?array {
		$this->logger->warning(
			$attempt->failureLog(error: $error),
			['providerId' => $attempt->providerId, 'vendor' => $attempt->client->getVendor(), 'message' => $error->getMessage()]
		);
		if ($attempt->run->allowFailover === true && ($error instanceof PermanentSmsProviderException) === false) {
			return null;
		}

		return $this->persistFailureAndAlert(
			failure: new SmsSendFailure(
				message: $attempt->run->message,
				providerId: $attempt->providerId,
				error: $error->getMessage(),
			),
		);
	}//end failedAttempt()

	/**
	 * Persist a sent message, count it against the budget and audit it.
	 *
	 * @param SmsProviderAttempt                                  $attempt The attempt that succeeded.
	 * @param array{externalMessageId: string, vendor: string} $result  What the provider answered.
	 *
	 * @return array<string, mixed> The sent envelope.
	 */
	private function recordSent(SmsProviderAttempt $attempt, array $result): array {
		$message = $attempt->run->message;
		$persisted = $this->persistOutbound(attempt: $attempt, externalMessageId: $result['externalMessageId']);
		$this->budgetService->recordSend(tenantId: $attempt->run->tenantId, providerId: $attempt->providerId);

		return [
			'status' => self::STATUS_SENT,
			'messageId' => $this->auditOutbound(message: $message, persisted: ($persisted ?? [])),
			'externalMessageId' => $result['externalMessageId'],
			'providerId' => $attempt->providerId,
			'vendor' => $result['vendor'],
		];
	}//end recordSent()

	/**
	 * Best-effort outbound contactmoment audit (REQ-OM-006).
	 *
	 * Resolves {@see ContactmomentService} lazily through the container and
	 * records the SMS send as a `channel: sms` contactmoment. Log-and-continue:
	 * an audit failure MUST never affect the send outcome.
	 *
	 * @param SmsMessage           $message   The message that was sent.
	 * @param array<string, mixed> $persisted The saved message row, or [] when saving failed.
	 *
	 * @return string The saved message id, or '' when saving failed.
	 *
	 * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
	 */
	private function auditOutbound(SmsMessage $message, array $persisted): string {
		$messageId = $this->extractId(payload: $persisted);
		$auditor = $this->contactmoments();
		if ($auditor === null) {
			return $messageId;
		}

		$auditor->recordOutboundMessage(
			channel: 'sms',
			subject: 'Outbound SMS',
			summary: $message->body,
			channelMetadata: [
				'platform' => 'sms',
				'direction' => 'outbound',
				'messageId' => $messageId,
				'conversationId' => (string)($persisted['conversationId'] ?? ''),
				'contactId' => $message->contactId,
			],
			clientId: (string)($message->context['clientId'] ?? ''),
			agent: (string)($message->context['agent'] ?? ''),
		);

		return $messageId;
	}//end auditOutbound()

	/**
	 * The contact moment service, resolved lazily; null when it is absent.
	 *
	 * @return ContactmomentService|null The service or null.
	 */
	private function contactmoments(): ?ContactmomentService {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ContactmomentService');
		} catch (Throwable $e) {
			return null;
		}

		if (($service instanceof ContactmomentService) === false) {
			return null;
		}

		return $service;
	}//end contactmoments()

	/**
	 * Handle an inbound SMS webhook.
	 *
	 * Verifies the signature using the channelProvider.webhookSecret,
	 * persists the inbound message, auto-detects STOP keywords, and
	 * fires placeholder-contact creation when the number is unknown.
	 *
	 * @param string $rawBody Raw request body.
	 * @param string $signature Signature header value.
	 * @param string $providerId channelProvider UUID.
	 *
	 * @return array{
	 *     status: string,
	 *     messageId?: string,
	 *     conversationId?: string,
	 *     unknownSender?: bool,
	 *     contactMomentId?: string,
	 *     optOutRecorded?: bool,
	 *     error?: string
	 * } Outcome envelope.
	 *
	 * A number that matches no contact gets no placeholder contact: a STOP is
	 * recorded on the number itself, anything else is logged as a contact
	 * moment for a person to pick up.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array {
		// A PublicPage with no user: the provider lookup is a read without RBAC; after the
		// signature check every write runs as the messaging service account.
		$row = $this->providerRepo->findByIdForWebhook(id: $providerId);
		if ($row === null) {
			return ['status' => 'providerUnknown'];
		}

		$client = $this->providerFactory->create(channelProvider: $row);
		if ($client === null) {
			return ['status' => 'vendorUnsupported'];
		}

		if ($client->verifySignature(rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'SmsAdapter.handleInboundWebhook: invalid signature',
				['providerId' => $providerId]
			);
			return ['status' => 'invalidSignature'];
		}

		$decoded = $this->decodeBody(rawBody: $rawBody);
		$body = (string)($decoded['body'] ?? ($decoded['Body'] ?? ''));
		$from = $this->phoneNormaliser->normaliseInbound(
			rawNumber: (string)($decoded['from'] ?? ($decoded['From'] ?? ''))
		);

		if ($from === '' || $body === '') {
			return ['status' => 'invalidPayload'];
		}

		return $this->asMessagingAccount(
			writes: fn (): array => $this->ingestInbound(providerId: $providerId, from: $from, body: $body)
		);
	}//end handleInboundWebhook()

	/**
	 * Persist a verified inbound SMS and act on it, as the messaging account.
	 * @param string $providerId channelProvider UUID.
	 * @param string $from       Sender in E.164.
	 * @param string $body       Message text.
	 *
	 * @return array<string, mixed> Outcome envelope.
	 */
	private function ingestInbound(string $providerId, string $from, string $body): array {
		$contactId = $this->findContactByPhone(phone: $from);
		$unknownSender = ($contactId === '');

		// An unknown number joins no conversation (they would all share the empty-contact one).
		$conversationId = '';
		if ($unknownSender === false) {
			$conversationId = $this->findOrOpenConversation(
				contactId: $contactId,
				providerId: $providerId,
				channel: 'sms',
				scope: self::LOOKUP_SCOPE,
			);
		}

		$inbound = new SmsInbound(providerId: $providerId, contactId: $contactId, from: $from, body: $body);
		$persisted = $this->persistInbound(inbound: $inbound, conversationId: $conversationId);

		return $this->actOnInbound(
			result: [
				'status' => 'received',
				'messageId' => $this->extractId(payload: $persisted ?? []),
				'conversationId' => $conversationId,
				'unknownSender' => $unknownSender,
				'optOutRecorded' => false,
			],
			inbound: $inbound,
		);
	}//end ingestInbound()

	/**
	 * Act on a persisted inbound SMS: a STOP opts out the contact, or the
	 * number alone when unknown; an unknown number's other messages are
	 * logged for a person; an opt-in keyword opts a known contact in.
	 *
	 * @param array<string, mixed> $result  Outcome envelope so far.
	 * @param SmsInbound           $inbound The message; an empty contact id is an unknown number.
	 *
	 * @return array<string, mixed> The completed envelope.
	 */
	private function actOnInbound(array $result, SmsInbound $inbound): array {
		if ($this->consentService->isOptOutKeyword(body: $inbound->body) === true) {
			$result['optOutRecorded'] = $this->recordInboundOptOut(inbound: $inbound);
			return $result;
		}

		if ($inbound->contactId === '') {
			$result['contactMomentId'] = $this->logUnknownSender(inbound: $inbound, messageId: (string)$result['messageId']);
			return $result;
		}

		$this->recordInboundOptIn(inbound: $inbound);

		return $result;
	}//end actOnInbound()

	/**
	 * Record the STOP of an inbound SMS.
	 *
	 * @param SmsInbound $inbound The STOP message.
	 *
	 * @return bool Whether the opt-out was recorded.
	 */
	private function recordInboundOptOut(SmsInbound $inbound): bool {
		return $this->consentService->recordOptOut(
			contactId: $inbound->contactId,
			channel: 'sms',
			source: 'keyword-stop',
			evidence: sprintf('Inbound SMS body "%s" matched STOP keyword', $inbound->body),
			address: $inbound->from,
		) !== null;
	}//end recordInboundOptOut()

	/**
	 * Log an SMS from an unknown number as a contact moment for a person.
	 *
	 * @param SmsInbound $inbound   The message.
	 * @param string     $messageId The stored message id.
	 *
	 * @return string The contact moment id, or empty when none was logged.
	 */
	private function logUnknownSender(SmsInbound $inbound, string $messageId): string {
		return (string)$this->contactmoments()?->recordInboundFromUnknownNumber(
			platform: 'sms',
			phone: $inbound->from,
			body: $inbound->body,
			messageId: $messageId,
		);
	}//end logUnknownSender()

	/**
	 * Record an opt-in when a known contact's SMS is an opt-in keyword.
	 *
	 * @param SmsInbound $inbound The message of a known contact.
	 *
	 * @return void
	 */
	private function recordInboundOptIn(SmsInbound $inbound): void {
		if ($this->consentService->isOptInKeyword(body: $inbound->body) === false) {
			return;
		}

		$this->consentService->recordOptIn(
			contactId: $inbound->contactId,
			channel: 'sms',
			source: 'chat-reply',
			evidence: sprintf('Inbound SMS body "%s" matched opt-in keyword', $inbound->body),
			address: $inbound->from,
		);
	}//end recordInboundOptIn()

	/**
	 * Producer of the ordered provider list for an outbound send.
	 *
	 * @param string|null $providerHint Pinned vendor or null.
	 *
	 * @return array<int, array<string, mixed>> Provider rows.
	 */
	private function orderedProvidersForSend(?string $providerHint): array {
		if ($providerHint !== null && $providerHint !== '') {
			$row = $this->providerRepo->findByVendor(kind: 'sms', vendor: $providerHint);
			if ($row === null) {
				return [];
			}

			return [$row];
		}

		return $this->providerRepo->listActive(kind: 'sms');
	}//end orderedProvidersForSend()

	/**
	 * Persist a `failed` outbound row, leave a system note, and
	 * notify the tenant admin.
	 *
	 * @param SmsSendFailure $failure The message, the provider it failed on (empty when none tried) and the error.
	 *
	 * @return array{status: string, error: string, providerId?: string} Outcome.
	 */
	private function persistFailureAndAlert(SmsSendFailure $failure): array {
		$this->persistMessage(payload: $this->failedMessagePayload(failure: $failure));
		$this->alertSendFailure(failure: $failure);

		return [
			'status' => self::STATUS_FAILED,
			'error' => $failure->error,
			'providerId' => $failure->providerId,
		];
	}//end persistFailureAndAlert()

	/**
	 * The failed outbound message row, in the contact's SMS conversation.
	 *
	 * @param SmsSendFailure $failure The failed send.
	 *
	 * @return array<string, mixed> The message payload.
	 */
	private function failedMessagePayload(SmsSendFailure $failure): array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $failure->message->contactId,
			providerId: $failure->providerId,
			channel: 'sms',
		);

		return ['contactId' => $failure->message->contactId, 'conversationId' => $conversationId]
			+ $this->failedMessageFields(failure: $failure);
	}//end failedMessagePayload()

	/**
	 * The fields of a failed outbound message after its contact and conversation.
	 *
	 * @param SmsSendFailure $failure The failed send.
	 *
	 * @return array<string, mixed> The message fields.
	 */
	private function failedMessageFields(SmsSendFailure $failure): array {
		return [
			'channel' => 'sms',
			'direction' => 'outbound',
			'body' => $failure->message->body,
			'providerId' => $failure->providerId,
			'deliveryStatus' => 'failed',
			'sentAt' => $this->nowIso(),
			'metadata' => ['error' => $failure->error],
		];
	}//end failedMessageFields()

	/**
	 * Notify the admin of a failed send; a notification that fails is logged.
	 *
	 * @param SmsSendFailure $failure The failed send.
	 *
	 * @return void
	 */
	private function alertSendFailure(SmsSendFailure $failure): void {
		try {
			$this->notifySendFailure(failure: $failure);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.persistFailureAndAlert: notification failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end alertSendFailure()

	/**
	 * Send the admin the failed-send notification.
	 *
	 * @param SmsSendFailure $failure The failed send.
	 *
	 * @return void
	 */
	private function notifySendFailure(SmsSendFailure $failure): void {
		$contactId = $failure->message->contactId;
		$this->notificationService->sendNotification(
			userId: 'admin',
			subject: 'messaging_sms_send_failed',
			parameters: ['contactId' => $contactId, 'providerId' => $failure->providerId, 'error' => $failure->error],
			objectType: 'message',
			objectId: $contactId,
		);
	}//end notifySendFailure()

	/**
	 * Persist an outbound message row.
	 *
	 * @param SmsProviderAttempt $attempt           The attempt that sent it.
	 * @param string             $externalMessageId Provider message id.
	 *
	 * @return array<string, mixed>|null Saved row.
	 */
	private function persistOutbound(SmsProviderAttempt $attempt, string $externalMessageId): ?array {
		$contactId = $attempt->run->message->contactId;
		$conversationId = $this->findOrOpenConversation(
			contactId: $contactId,
			providerId: $attempt->providerId,
			channel: 'sms',
		);

		return $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'sms',
				'direction' => 'outbound',
				'body' => $attempt->run->message->body,
				'providerId' => $attempt->providerId,
				'externalMessageId' => $externalMessageId,
				'deliveryStatus' => 'queued',
				'sentAt' => $this->nowIso(),
			],
		);
	}//end persistOutbound()

	/**
	 * Persist an inbound message row.
	 *
	 * Called only from the signed public webhook, inside the messaging account.
	 *
	 * @param SmsInbound $inbound        The message.
	 * @param string     $conversationId Conversation UUID, or empty for an unknown number.
	 *
	 * @return array<string, mixed>|null Saved row.
	 */
	private function persistInbound(SmsInbound $inbound, string $conversationId): ?array {
		return $this->persistMessage(
			payload: [
				'contactId' => $inbound->contactId,
				'conversationId' => $conversationId,
				'channel' => 'sms',
				'direction' => 'inbound',
				'body' => $inbound->body,
				'providerId' => $inbound->providerId,
				'deliveryStatus' => 'delivered',
				'sentAt' => $this->nowIso(),
			],
		);
	}//end persistInbound()

	/**
	 * The inbound SMS a send answers, when it is this contact's own.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $messageId The inbound message id the agent answers, or empty.
	 *
	 * @return string|null The id, or null when the send is no reply.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	private function verifiedInbound(string $contactId, string $messageId): ?string {
		$objectService = $this->getObjectService();
		if ($contactId === '' || $messageId === '' || $objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $messageId,
				options: new ObjectFindOptions(
					register: $this->getRegisterSlug(),
					schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
				)
			);
		} catch (Throwable $e) {
			return null;
		}

		$row = $this->toArray(value: $found);
		$isOwnInbound = ((string)($row['contactId'] ?? '') === $contactId
			&& (string)($row['channel'] ?? '') === 'sms'
			&& (string)($row['direction'] ?? '') === 'inbound');
		if ($isOwnInbound === false) {
			return null;
		}

		return $messageId;
	}//end verifiedInbound()

	/**
	 * Persist a message payload via OpenRegister.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<string, mixed>|null Saved row.
	 */
	private function persistMessage(array $payload): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.persistMessage: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end persistMessage()

	/**
	 * Look up the open conversation for (contact, provider, channel)
	 * or open a new one.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $providerId Provider UUID.
	 * @param string $channel Channel.
	 * @param array<string, bool> $scope OpenRegister access scope for the lookup (LOOKUP_SCOPE from the webhook); the open runs as the caller.
	 *
	 * @return string Conversation UUID.
	 */
	private function findOrOpenConversation(string $contactId, string $providerId, string $channel, array $scope = []): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'providerId' => $providerId,
						'channel' => $channel,
						'status' => 'open',
						'register' => $this->getRegisterSlug(),
						'schema' => $schema,
					],
				],
				_rbac: ($scope['_rbac'] ?? true),
				_multitenancy: ($scope['_multitenancy'] ?? true),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.findOrOpenConversation: findAll failed',
				['exception' => $e->getMessage()]
			);
			$rows = [];
		}

		if (is_array($rows) === true && $rows !== []) {
			return $this->extractId(payload: $this->toArray(value: $rows[0]));
		}

		try {
			$saved = $objectService->saveObject(
				object: [
					'contactId' => $contactId,
					'providerId' => $providerId,
					'channel' => $channel,
					'status' => 'open',
					'lastInboundAt' => $this->nowIso(),
				],
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.findOrOpenConversation: open failed',
				['exception' => $e->getMessage()]
			);
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $saved));
	}//end findOrOpenConversation()

	/**
	 * Decode an inbound webhook body — accepts JSON or form-encoded.
	 *
	 * @param string $rawBody Raw body.
	 *
	 * @return array<string, mixed> Decoded payload.
	 */
	private function decodeBody(string $rawBody): array {
		$trimmed = trim($rawBody);
		if ($trimmed === '') {
			return [];
		}

		if ($trimmed[0] === '{' || $trimmed[0] === '[') {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		$parsed = [];
		parse_str($trimmed, $parsed);
		return $parsed;
	}//end decodeBody()

	/**
	 * Extract the most relevant phone number from a contact row.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 *
	 * @return string Phone number (E.164) or empty.
	 */
	private function extractPhone(array $contact): string {
		foreach (['phoneNumber', 'mobile', 'telephone', 'phone'] as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end extractPhone()

	/**
	 * Tenant id — defaults to the NC instance id when none is set.
	 *
	 * @return string Tenant id.
	 */
	private function getTenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}//end getTenantId()

	/**
	 * Resolve a schema slug app-config override.
	 *
	 * @param string $key App-config key.
	 * @param string $default Built-in default.
	 *
	 * @return string Slug.
	 */
	private function resolveSchemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}//end resolveSchemaSlug()

	/**
	 * Register slug.
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object|null Service or null.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SmsAdapter.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Extract a UUID / id / slug from a payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id or empty.
	 */
	private function extractId(array $payload): string {
		$id = $this->firstScalarValue(source: $payload, keys: ['uuid', 'id', 'slug']);
		if ($id !== '') {
			return $id;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalarValue(source: $payload['@self'], keys: ['uuid', 'id', 'slug']);
		}

		return '';
	}//end extractId()

	/**
	 * Return the first non-empty scalar value found under any of the given keys.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @param array<int, string> $keys Keys to try, in order.
	 *
	 * @return string The first matching value cast to string, or empty.
	 */
	private function firstScalarValue(array $source, array $keys): string {
		foreach ($keys as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end firstScalarValue()

	/**
	 * Current ISO 8601 UTC timestamp.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
