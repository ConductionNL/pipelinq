<?php

/**
 * Pipelinq WhatsAppAdapter.
 *
 * WhatsApp Cloud API + BSP adapter. Manages the 24-hour session
 * window, template-vs-free-form gating, template parameter
 * validation, provider failover (Meta primary, BSP fallback), and
 * signature-verified inbound webhook ingestion. Every outbound goes
 * through ConsentService + BudgetService before reaching the
 * provider client.
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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\WhatsAppAdapterInterface;
use OCA\Pipelinq\Service\Provider\InboundWebhookDelivery;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\WhatsAppInbound;
use OCA\Pipelinq\Service\Provider\WhatsAppMessage;
use OCA\Pipelinq\Service\Provider\WhatsAppProviderAttempt;
use OCA\Pipelinq\Service\Provider\WhatsAppSendFailure;
use OCA\Pipelinq\Service\Provider\WhatsAppSendRun;
use OCA\Pipelinq\Service\Provider\WhatsAppTemplateRequest;
use OCA\Pipelinq\Service\Provider\WhatsAppTemplateSend;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * WhatsAppAdapter — outbound + inbound WhatsApp surface.
 *
 * Public entry points:
 * - send(contact, body, templateId?, parameters?) — template or
 *   free-form send with full compliance gating.
 * - handleInboundWebhook(rawBody, signature, providerId) —
 *   signature-verified webhook ingestion.
 * - parseTemplatePlaceholders(templateBody) — pure utility that
 *   counts {{N}} placeholders.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires consent +
 *  budget + provider client + media + repository + cost capture +
 *  notifications + OR.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Aggregates the whole
 *  outbound/inbound WhatsApp lifecycle (send orchestration, failover,
 *  webhook ingestion, OR persistence helpers) as many small,
 *  single-purpose methods; splitting it would scatter one cohesive
 *  channel-adapter concern across several classes.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cohesion
 *  rationale: the length reflects breadth, not tangled logic — each
 *  extracted method is independently simple (verified via phpmd's
 *  per-method thresholds).
 * @SuppressWarnings(PHPMD.TooManyMethods)           The 2026-07 phpmd
 *  cleanup extracted several private single-purpose helpers
 *  (resolveSendContext, resolveTemplateForSend, attemptProviderSend,
 *  templateIdForSend, latestInboundTimestamp, firstScalarId)
 *  specifically to bring per-method complexity under threshold; further
 *  splitting the class would scatter one channel-adapter concern without
 *  reducing real coupling.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppAdapter implements WhatsAppAdapterInterface {
	use PayloadToArray;
	use WritesAsMessagingAccount;

	/**
	 * OpenRegister access scope for the webhook's LOOKUPS (contact,
	 * conversation): reads without RBAC. Every write runs as the messaging
	 * service account with the checks on (as the SMS webhook).
	 *
	 * @var array<string, bool>
	 */
	private const LOOKUP_SCOPE = ['_rbac' => false, '_multitenancy' => false];

	/**
	 * Default register slug.
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
	 * Default contact schema slug.
	 */
	private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * Default messageTemplate schema slug.
	 */
	private const DEFAULT_TEMPLATE_SCHEMA_SLUG = 'messageTemplate';

	/**
	 * 24h window in seconds — Meta-imposed customer-service window.
	 */
	private const SESSION_WINDOW_SECONDS = 86400;

	/**
	 * Send-result envelope statuses.
	 */
	public const STATUS_SENT = 'sent';
	public const STATUS_CONSENT_MISSING = 'consentMissing';
	public const STATUS_BUDGET_EXCEEDED = 'budgetExceeded';
	public const STATUS_NO_PROVIDER = 'noProviderAvailable';
	public const STATUS_SESSION_WINDOW_EXPIRED = 'sessionWindowExpired';
	public const STATUS_TEMPLATE_NOT_FOUND = 'templateNotFound';
	public const STATUS_TEMPLATE_NOT_APPROVED = 'templateNotApproved';
	public const STATUS_TEMPLATE_MISMATCH = 'templateParameterMismatch';
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param ChannelProviderRepository $providerRepo Provider read-side.
	 * @param WhatsAppProviderClient $providerClient Vendor transport.
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
		private WhatsAppProviderClient $providerClient,
		private ConsentService $consentService,
		private BudgetService $budgetService,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
		private PhoneNormaliser $phoneNormaliser,
	) {
	}//end __construct()

	/**
	 * Send a WhatsApp message.
	 *
	 * Decision tree:
	 * 1. Consent gate.
	 * 2. Resolve a phone number on the contact.
	 * 3. If templateId set → load + verify approved + validate
	 *    parameters. Send-template via the priority-ordered provider
	 *    list.
	 * 4. Else free-form → check session window; if expired return
	 *    sessionWindowExpired; otherwise send-text.
	 * 5. Budget gate per provider attempt; on transient failure try
	 *    the next priority. Persist + recordSend on success.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 * @param string $body Free-form body (ignored when templateId set).
	 * @param string|null $templateId Template UUID/slug or null for free-form.
	 * @param array<int, string> $parameters Positional template parameters.
	 * @param array<string, mixed> $context Optional send context for the
	 *                                      outbound audit (`clientId`, `agent`).
	 *
	 * @return array{
	 *     status: string,
	 *     messageId?: string,
	 *     externalMessageId?: string,
	 *     providerId?: string,
	 *     vendor?: string,
	 *     expected?: int,
	 *     given?: int,
	 *     error?: string
	 * } Send outcome.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function send(
		array $contact,
		string $body,
		?string $templateId = null,
		array $parameters = [],
		array $context = [],
	): array {
		$contactId = $this->extractId(payload: $contact);
		$toNumber = $this->extractPhone(contact: $contact);

		if ($toNumber === '') {
			return ['status' => self::STATUS_FAILED, 'error' => 'no recipient phone number'];
		}

		$isBusinessInitiated = (($templateId ?? '') !== '');
		$inReplyTo = null;
		if ($isBusinessInitiated === false) {
			// A free-form send is an answer inside the session the contact
			// opened, so integriq is asked about it as a reply (hydra
			// decision 2). A template send is one pipelinq starts.
			$inReplyTo = $this->replyTarget(contactId: $contactId, explicit: (string)($context['inReplyTo'] ?? ''));
		}

		if ($this->consentForSend(contactId: $contactId, businessInitiated: $isBusinessInitiated, address: $toNumber, inReplyTo: $inReplyTo) === false) {
			return ['status' => self::STATUS_CONSENT_MISSING];
		}

		$language = (string)$this->appConfig->getValueString(AppIdentity::APP_ID, 'whatsapp.default_language', 'nl');
		$resolved = $this->resolveSendContext(
			request: new WhatsAppTemplateRequest(templateId: $templateId, parameters: $parameters, language: $language),
			contactId: $contactId
		);
		if ($resolved['error'] !== null) {
			return $resolved['error'];
		}

		$template = $resolved['template'];
		$templateName = $resolved['templateName'];
		$language = $resolved['language'];

		$tenantId = $this->getTenantId();
		$ordered = $this->orderedProvidersForSend(templateProviderId: (string)($template['providerId'] ?? ''));

		if ($ordered === []) {
			return ['status' => self::STATUS_NO_PROVIDER];
		}

		$run = new WhatsAppSendRun(
			message: new WhatsAppMessage(contactId: $contactId, toNumber: $toNumber, body: $body, context: $context),
			templateSend: new WhatsAppTemplateSend(
				templateName: $templateName,
				language: $language,
				parameters: $parameters,
				template: $template,
			),
			tenantId: $tenantId,
		);
		foreach ($ordered as $row) {
			$outcome = $this->attemptProviderSend(row: $row, run: $run);
			if ($outcome !== null) {
				return $outcome;
			}
		}

		return $this->persistFailureAndAlert(
			run: $run,
			failure: new WhatsAppSendFailure(providerId: '', error: 'all WhatsApp providers returned transient errors'),
		);
	}//end send()

	/**
	 * Resolve the send context: template resolution for a template send, or
	 * the in-window session check for a free-form send.
	 *
	 * Extracted from {@see send()} so the orchestrator stays within the
	 * complexity budget; behaviour is unchanged — the two branches are
	 * mutually exclusive on whether `$templateId` is set.
	 *
	 * @param WhatsAppTemplateRequest $request   Template id, parameters and default language.
	 * @param string                  $contactId Contact UUID (session-window lookup).
	 *
	 * @return array{
	 *     error: array<string, mixed>|null,
	 *     template?: array<string, mixed>|null,
	 *     templateName?: string,
	 *     language?: string
	 * } Resolved context, or an `error` outcome to return immediately.
	 */
	private function resolveSendContext(WhatsAppTemplateRequest $request, string $contactId): array {
		if ($request->isTemplateSend() === true) {
			return $this->templateSendContext(request: $request);
		}

		return $this->freeFormSendContext(contactId: $contactId, language: $request->language);
	}//end resolveSendContext()

	/**
	 * The send context of a template send: the resolved template, or the
	 * refusal to return.
	 *
	 * @param WhatsAppTemplateRequest $request Template id, parameters and default language.
	 *
	 * @return array<string, mixed> The context, `error` null on success.
	 */
	private function templateSendContext(WhatsAppTemplateRequest $request): array {
		$resolved = $this->resolveTemplateForSend(request: $request);
		if ($resolved['ok'] === false) {
			return ['error' => $resolved['error']];
		}

		return [
			'error' => null,
			'template' => $resolved['template'],
			'templateName' => $resolved['templateName'],
			'language' => $resolved['language'],
		];
	}//end templateSendContext()

	/**
	 * The send context of a free-form send, which requires an in-window session.
	 *
	 * @param string $contactId Contact UUID (session-window lookup).
	 * @param string $language  Default language (app-config).
	 *
	 * @return array<string, mixed> The context, or the session-expired refusal.
	 */
	private function freeFormSendContext(string $contactId, string $language): array {
		if ($this->isWithinSessionWindow(contactId: $contactId) === false) {
			return ['error' => ['status' => self::STATUS_SESSION_WINDOW_EXPIRED]];
		}

		return [
			'error' => null,
			'template' => null,
			'templateName' => '',
			'language' => $language,
		];
	}//end freeFormSendContext()

	/**
	 * Consent gate for a WhatsApp send.
	 *
	 * A business-initiated send (a template send, normally outside the 24h
	 * session window — including SLA escalations) requires the contact's
	 * latest `messagingConsentRecord` for whatsapp to be `opted-in` (Meta
	 * business-messaging policy). A within-window free-form reply keeps the
	 * opt-out-only gate (customer initiated the session).
	 *
	 * @param string $contactId Contact UUID.
	 * @param bool $businessInitiated Whether this is a template / business-initiated send.
	 * @param string $address The number the message goes to.
	 * @param string|null $inReplyTo The inbound message a free-form send answers.
	 *
	 * @return bool True when the send may proceed.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	private function consentForSend(string $contactId, bool $businessInitiated, string $address = '', ?string $inReplyTo = null): bool {
		if ($businessInitiated === true) {
			return $this->consentService->canSendBusinessInitiated(contactId: $contactId, channel: 'whatsapp', address: $address);
		}

		return $this->consentService->canSend(contactId: $contactId, channel: 'whatsapp', address: $address, inReplyTo: $inReplyTo);
	}//end consentForSend()

	/**
	 * The inbound message a free-form send answers, inside the session window.
	 *
	 * An explicit id counts only when it is one of this contact's inbound
	 * WhatsApp messages inside the window; without one, the latest inbound
	 * message inside the window is the one answered.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $explicit  The inbound message id the agent answers, or empty.
	 *
	 * @return string|null The message id, or null when the send is no reply.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	private function replyTarget(string $contactId, string $explicit): ?string {
		$inWindow = $this->inboundInWindow(contactId: $contactId);
		if ($explicit !== '') {
			if (isset($inWindow[$explicit]) === true) {
				return $explicit;
			}

			return null;
		}

		if ($inWindow === []) {
			return null;
		}

		arsort($inWindow);
		return (string)array_key_first($inWindow);
	}//end replyTarget()

	/**
	 * This contact's answerable inbound WhatsApp messages inside the session
	 * window.
	 *
	 * A STOP is the contact's last word: it and everything before it are not
	 * answerable. Integriq lets a reply through an opt-out, and the STOP opens
	 * the session itself, so without this the next free-form send after a
	 * STOP went out as an answer to it.
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return array<string,int> Message id to its sent time.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	private function inboundInWindow(string $contactId): array {
		$objectService = $this->getObjectService();
		if ($contactId === '' || $objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => 'whatsapp',
						'direction' => 'inbound',
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(key: 'message_schema', default: self::DEFAULT_MESSAGE_SCHEMA_SLUG),
					],
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$found = [];
		$lastStop = 0;
		foreach ((array)$rows as $raw) {
			$row = $this->toArray(value: $raw);
			$sentAt = (int)strtotime((string)($row['sentAt'] ?? ''));
			$messageId = $this->extractId(payload: $row);
			$isOwn = ((string)($row['contactId'] ?? $contactId) === $contactId);
			if ($messageId !== '' && $isOwn === true && (time() - $sentAt) < self::SESSION_WINDOW_SECONDS) {
				$found[$messageId] = $sentAt;
				if ($this->consentService->isOptOutKeyword(body: (string)($row['body'] ?? '')) === true) {
					$lastStop = max($lastStop, $sentAt);
				}
			}
		}

		return array_filter($found, static fn (int $sentAt): bool => $sentAt > $lastStop);
	}//end inboundInWindow()

	/**
	 * Resolve + validate a template send.
	 *
	 * Extracted from {@see send()} so the orchestrator stays within the
	 * complexity budget; behaviour (template load / approval / parameter
	 * count checks) is unchanged.
	 *
	 * @param WhatsAppTemplateRequest $request Template id, parameters and the
	 *                                         fallback language when the template
	 *                                         does not specify one.
	 *
	 * @return array{
	 *     ok: bool,
	 *     error?: array<string, mixed>,
	 *     template?: array<string, mixed>,
	 *     templateName?: string,
	 *     language?: string
	 * } Resolution outcome.
	 */
	private function resolveTemplateForSend(WhatsAppTemplateRequest $request): array {
		$template = $this->loadTemplate(id: (string)$request->templateId);
		$refusal = $this->templateRefusal(template: $template);
		if ($refusal !== null) {
			return ['ok' => false, 'error' => $refusal];
		}

		$mismatch = $this->parameterMismatch(template: $template, parameters: $request->parameters);
		if ($mismatch !== null) {
			return ['ok' => false, 'error' => $mismatch];
		}

		return $this->templateResolution(template: $template, fallbackLanguage: $request->language);
	}//end resolveTemplateForSend()

	/**
	 * Why a template cannot be sent: missing, or not approved.
	 *
	 * @param array<string, mixed>|null $template The loaded template, or null.
	 *
	 * @return array<string, string>|null The refusal, or null when it may be sent.
	 */
	private function templateRefusal(?array $template): ?array {
		if ($template === null) {
			return ['status' => self::STATUS_TEMPLATE_NOT_FOUND];
		}

		if ((string)($template['status'] ?? '') !== 'approved') {
			return ['status' => self::STATUS_TEMPLATE_NOT_APPROVED];
		}

		return null;
	}//end templateRefusal()

	/**
	 * The refusal for parameters that do not match the template's placeholders.
	 *
	 * @param array<string, mixed> $template   The template.
	 * @param array<int, string>   $parameters Positional template parameters.
	 *
	 * @return array<string, mixed>|null The mismatch, or null when they match.
	 */
	private function parameterMismatch(array $template, array $parameters): ?array {
		$expected = $this->parseTemplatePlaceholders(templateBody: (string)($template['body'] ?? ''));
		$given = count($parameters);
		if ($expected === $given) {
			return null;
		}

		return [
			'status' => self::STATUS_TEMPLATE_MISMATCH,
			'expected' => $expected,
			'given' => $given,
		];
	}//end parameterMismatch()

	/**
	 * A resolved template send: the template, its external name and its
	 * language, or the fallback when it has none.
	 *
	 * @param array<string, mixed> $template         The approved template.
	 * @param string               $fallbackLanguage The default language.
	 *
	 * @return array{ok: bool, template: array<string, mixed>, templateName: string, language: string}
	 */
	private function templateResolution(array $template, string $fallbackLanguage): array {
		return [
			'ok' => true,
			'template' => $template,
			'templateName' => (string)($template['externalId'] ?? ''),
			'language' => (string)($template['language'] ?? $fallbackLanguage),
		];
	}//end templateResolution()

	/**
	 * Attempt a send through one provider row.
	 *
	 * A spent budget, a transient provider failure or any unexpected
	 * throwable means "try the next provider" (null); a permanent provider
	 * failure or a successful send is terminal.
	 *
	 * @param array<string, mixed> $row Provider row.
	 * @param WhatsAppSendRun      $run The send this attempt belongs to.
	 *
	 * @return array<string, mixed>|null Terminal outcome, or null to try the next provider.
	 */
	private function attemptProviderSend(array $row, WhatsAppSendRun $run): ?array {
		$attempt = new WhatsAppProviderAttempt(run: $run, row: $row, providerId: $this->extractId(payload: $row));
		if ($this->budgetService->canSend(tenantId: $run->tenantId, providerId: $attempt->providerId) === false) {
			return null;
		}

		try {
			$result = $this->sendThrough(attempt: $attempt);
		} catch (Throwable $e) {
			return $this->failedAttempt(attempt: $attempt, error: $e);
		}

		return $this->recordSent(attempt: $attempt, result: $result);
	}//end attemptProviderSend()

	/**
	 * Send through the attempt's provider: a template send when a template
	 * name is resolved, free-form otherwise.
	 *
	 * @param WhatsAppProviderAttempt $attempt The attempt.
	 *
	 * @return array{externalMessageId: string, vendor: string} The provider's answer.
	 */
	private function sendThrough(WhatsAppProviderAttempt $attempt): array {
		$message = $attempt->run->message;
		$templateSend = $attempt->run->templateSend;
		if ($templateSend->templateName !== '') {
			return $this->providerClient->sendTemplate(
				channelProvider: $attempt->row,
				phoneNumber: $message->toNumber,
				templateName: $templateSend->templateName,
				language: $templateSend->language,
				parameters: $templateSend->parameters,
			);
		}

		return $this->providerClient->sendFreeForm(
			channelProvider: $attempt->row,
			phoneNumber: $message->toNumber,
			body: $message->body,
		);
	}//end sendThrough()

	/**
	 * Log a failed attempt; a permanent failure is stored and alerted, any
	 * other failure hands over to the next provider.
	 *
	 * @param WhatsAppProviderAttempt $attempt The attempt.
	 * @param Throwable               $error   What the client threw.
	 *
	 * @return array<string, mixed>|null The failure outcome, or null to try the next provider.
	 */
	private function failedAttempt(WhatsAppProviderAttempt $attempt, Throwable $error): ?array {
		$this->logger->warning(
			$attempt->failureLog(error: $error),
			['providerId' => $attempt->providerId, 'message' => $error->getMessage()]
		);
		if (($error instanceof PermanentSmsProviderException) === false) {
			return null;
		}

		return $this->persistFailureAndAlert(
			run: $attempt->run,
			failure: new WhatsAppSendFailure(providerId: $attempt->providerId, error: $error->getMessage()),
		);
	}//end failedAttempt()

	/**
	 * Store, count and audit a send that went out.
	 *
	 * @param WhatsAppProviderAttempt                          $attempt The attempt.
	 * @param array{externalMessageId: string, vendor: string} $result  The provider's answer.
	 *
	 * @return array<string, mixed> The sent outcome.
	 */
	private function recordSent(WhatsAppProviderAttempt $attempt, array $result): array {
		$persisted = ($this->persistOutbound(attempt: $attempt, externalMessageId: $result['externalMessageId']) ?? []);
		$this->budgetService->recordSend(tenantId: $attempt->run->tenantId, providerId: $attempt->providerId);
		$this->auditOutbound(run: $attempt->run, persisted: $persisted);

		return [
			'status' => self::STATUS_SENT,
			'messageId' => $this->extractId(payload: $persisted),
			'externalMessageId' => $result['externalMessageId'],
			'providerId' => $attempt->providerId,
			'vendor' => $result['vendor'],
		];
	}//end recordSent()

	/**
	 * Best-effort outbound contactmoment audit (REQ-OM-006).
	 *
	 * Resolves {@see ContactmomentService} lazily through the container and
	 * records the WhatsApp send as a `channel: chat` contactmoment with
	 * `channelMetadata.platform = whatsapp` (omnichannel convention).
	 * Log-and-continue: an audit failure MUST never affect the send outcome.
	 *
	 * @param WhatsAppSendRun      $run       The send.
	 * @param array<string, mixed> $persisted The stored message row, or empty when storing failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten
	 */
	private function auditOutbound(WhatsAppSendRun $run, array $persisted): void {
		$auditor = $this->contactmoments();
		if ($auditor === null) {
			return;
		}

		$auditor->recordOutboundMessage(
			channel: 'chat',
			subject: 'Outbound WhatsApp',
			summary: $run->outboundBody(),
			channelMetadata: [
				'platform' => 'whatsapp',
				'direction' => 'outbound',
				'messageId' => $this->extractId(payload: $persisted),
				'conversationId' => (string)($persisted['conversationId'] ?? ''),
				'contactId' => $run->message->contactId,
			],
			clientId: (string)($run->message->context['clientId'] ?? ''),
			agent: (string)($run->message->context['agent'] ?? ''),
		);
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
	 * Outbound templateId: the resolved template's id, or empty for free-form.
	 *
	 * @param array<string, mixed>|null $template Resolved template row, or null for free-form.
	 *
	 * @return string
	 */
	private function templateIdForSend(?array $template): string {
		if ($template !== null) {
			return $this->extractId(payload: $template);
		}

		return '';
	}//end templateIdForSend()

	/**
	 * Handle a Meta `messages` inbound webhook.
	 *
	 * Validates the X-Hub-Signature-256, persists the inbound message,
	 * advances a known contact's conversation `lastInboundAt`, and fires
	 * automatic STOP / opt-in detection. A number that matches no contact
	 * gets no placeholder contact: a STOP is recorded on the number itself,
	 * anything else is logged as a contact moment for a person to pick up.
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
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array {
		// The webhook is a PublicPage with no user. The provider lookup is a
		// read without RBAC and its row stays in-process; after the signature
		// check every write runs as the messaging service account.
		$row = $this->providerRepo->findByIdForWebhook(id: $providerId);
		if ($row === null) {
			return ['status' => 'providerUnknown'];
		}

		$delivery = new InboundWebhookDelivery(rawBody: $rawBody, signature: $signature, providerId: $providerId);
		if ($this->hasValidSignature(row: $row, delivery: $delivery) === false) {
			return ['status' => 'invalidSignature'];
		}

		return $this->ingestVerifiedDelivery(row: $row, delivery: $delivery);
	}//end handleInboundWebhook()

	/**
	 * Whether a delivery carries a valid signature for its provider row; an
	 * invalid one is logged.
	 *
	 * @param array<string, mixed>   $row      The provider row.
	 * @param InboundWebhookDelivery $delivery The delivery.
	 *
	 * @return bool True when the signature verifies.
	 */
	private function hasValidSignature(array $row, InboundWebhookDelivery $delivery): bool {
		$valid = $this->providerClient->verifySignature(channelProvider: $row, rawBody: $delivery->rawBody, signature: $delivery->signature);
		if ($valid === false) {
			$this->logger->warning(
				'WhatsAppAdapter.handleInboundWebhook: invalid signature',
				['providerId' => $delivery->providerId]
			);
		}

		return $valid;
	}//end hasValidSignature()

	/**
	 * Read a verified delivery's message and ingest it as the messaging
	 * account; a message without a sender is refused.
	 *
	 * @param array<string, mixed>   $row      The provider row.
	 * @param InboundWebhookDelivery $delivery The verified delivery.
	 *
	 * @return array<string, mixed> Outcome envelope.
	 */
	private function ingestVerifiedDelivery(array $row, InboundWebhookDelivery $delivery): array {
		$extracted = $this->extractMetaMessage(rawBody: $delivery->rawBody);
		$from = $this->phoneNormaliser->normaliseInbound(rawNumber: $extracted['from']);

		if ($from === '') {
			return ['status' => 'invalidPayload'];
		}

		return $this->asMessagingAccount(
			writes: fn (): array => $this->ingestInbound(providerRow: $row, providerId: $delivery->providerId, extracted: $extracted, from: $from)
		);
	}//end ingestVerifiedDelivery()

	/**
	 * Persist a verified inbound WhatsApp message and act on it, as the
	 * messaging account.
	 *
	 * @param array<string, mixed> $providerRow Provider row.
	 * @param string               $providerId  channelProvider UUID.
	 * @param array<string, mixed> $extracted   The Meta message (from, body, raw).
	 * @param string               $from        Sender in E.164.
	 *
	 * @return array<string, mixed> Outcome envelope.
	 */
	private function ingestInbound(array $providerRow, string $providerId, array $extracted, string $from): array {
		$body = (string)$extracted['body'];
		$contactId = $this->findContactByPhone(phone: $from);

		// An unknown number has no conversation to join: every unknown number
		// would share the one keyed on an empty contact.
		$conversationId = '';
		if ($contactId !== '') {
			$conversationId = $this->findOrOpenConversation(
				contactId: $contactId,
				providerId: $providerId,
				channel: 'whatsapp',
				scope: self::LOOKUP_SCOPE,
			);
		}

		$windowExpiresAt = gmdate('Y-m-d\TH:i:s\Z', (time() + self::SESSION_WINDOW_SECONDS));

		$persisted = $this->persistMessage(
			payload: [
				'contactId' => $contactId,
				'conversationId' => $conversationId,
				'channel' => 'whatsapp',
				'direction' => 'inbound',
				'body' => $body,
				'providerId' => $providerId,
				'deliveryStatus' => 'delivered',
				'sentAt' => $this->nowIso(),
				'windowExpiresAt' => $windowExpiresAt,
				'metadata' => ['raw' => $extracted],
			],
		);

		$this->touchConversationWindow(conversationId: $conversationId, windowExpiresAt: $windowExpiresAt);

		return $this->actOnInbound(
			result: [
				'status' => 'received',
				'messageId' => $this->extractId(payload: $persisted ?? []),
				'conversationId' => $conversationId,
				'unknownSender' => ($contactId === ''),
				'optOutRecorded' => false,
			],
			inbound: new WhatsAppInbound(contactId: $contactId, from: $from, body: $body, providerRow: $providerRow),
		);
	}//end ingestInbound()

	/**
	 * Act on a persisted inbound message: a STOP opts the sender out (the
	 * contact, or the number alone when it is unknown), a message from an
	 * unknown number is logged for a person, an opt-in keyword from a known
	 * contact opts them in.
	 *
	 * @param array<string, mixed> $result  Outcome envelope so far.
	 * @param WhatsAppInbound      $inbound The message; an empty contact id is an unknown number.
	 *
	 * @return array<string, mixed> The completed envelope.
	 */
	private function actOnInbound(array $result, WhatsAppInbound $inbound): array {
		if ($this->consentService->isOptOutKeyword(body: $inbound->body) === true) {
			return $this->recordInboundOptOut(result: $result, inbound: $inbound);
		}

		if ($inbound->contactId === '') {
			return $this->logUnknownSender(result: $result, inbound: $inbound);
		}

		$this->recordInboundOptIn(inbound: $inbound);

		return $result;
	}//end actOnInbound()

	/**
	 * Record the STOP of an inbound message and acknowledge it when it was recorded.
	 *
	 * @param array<string, mixed> $result  Outcome envelope so far.
	 * @param WhatsAppInbound      $inbound The STOP message.
	 *
	 * @return array<string, mixed> The envelope with `optOutRecorded` set.
	 */
	private function recordInboundOptOut(array $result, WhatsAppInbound $inbound): array {
		$result['optOutRecorded'] = $this->consentService->recordOptOut(
			contactId: $inbound->contactId,
			channel: 'whatsapp',
			source: 'keyword-stop',
			evidence: sprintf('Inbound WhatsApp body "%s" matched STOP keyword', $inbound->body),
			address: $inbound->from,
		) !== null;
		if ($result['optOutRecorded'] === true) {
			// Free-form acknowledgement is allowed under WhatsApp's
			// utility-category rules within the session this very
			// inbound message opened.
			$this->sendOptOutAcknowledgement(phone: $inbound->from, providerRow: $inbound->providerRow);
		}

		return $result;
	}//end recordInboundOptOut()

	/**
	 * Log a message from an unknown number as a contact moment for a person.
	 *
	 * @param array<string, mixed> $result  Outcome envelope so far.
	 * @param WhatsAppInbound      $inbound The message.
	 *
	 * @return array<string, mixed> The envelope with `contactMomentId` set.
	 */
	private function logUnknownSender(array $result, WhatsAppInbound $inbound): array {
		$result['contactMomentId'] = (string)$this->contactmoments()?->recordInboundFromUnknownNumber(
			platform: 'whatsapp',
			phone: $inbound->from,
			body: $inbound->body,
			messageId: (string)$result['messageId'],
		);
		return $result;
	}//end logUnknownSender()

	/**
	 * Record an opt-in when a known contact's message is an opt-in keyword.
	 *
	 * @param WhatsAppInbound $inbound The message of a known contact.
	 *
	 * @return void
	 */
	private function recordInboundOptIn(WhatsAppInbound $inbound): void {
		if ($this->consentService->isOptInKeyword(body: $inbound->body) === false) {
			return;
		}

		$this->consentService->recordOptIn(
			contactId: $inbound->contactId,
			channel: 'whatsapp',
			source: 'chat-reply',
			evidence: sprintf('Inbound WhatsApp body "%s" matched opt-in keyword', $inbound->body),
			address: $inbound->from,
		);
	}//end recordInboundOptIn()

	/**
	 * Count {{N}} placeholders in a template body.
	 *
	 * @param string $templateBody Template body.
	 *
	 * @return int Distinct placeholder positions.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function parseTemplatePlaceholders(string $templateBody): int {
		$matches = [];
		preg_match_all('/\{\{(\d+)\}\}/u', $templateBody, $matches);
		$unique = array_unique(array_map('intval', $matches[1]));
		return count($unique);
	}//end parseTemplatePlaceholders()

	/**
	 * Whether the contact has an in-window inbound message.
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return bool True if the most recent inbound is within 24h.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function isWithinSessionWindow(string $contactId): bool {
		if ($contactId === '') {
			return false;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => 'whatsapp',
						'direction' => 'inbound',
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(
							key: 'message_schema',
							default: self::DEFAULT_MESSAGE_SCHEMA_SLUG
						),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.isWithinSessionWindow: findAll failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

		if (is_array($rows) === false || $rows === []) {
			return false;
		}

		$latestTimestamp = $this->latestInboundTimestamp(rows: $rows);
		if ($latestTimestamp === 0) {
			return false;
		}

		return ((time() - $latestTimestamp) < self::SESSION_WINDOW_SECONDS);
	}//end isWithinSessionWindow()

	/**
	 * Latest `sentAt` timestamp across a set of inbound message rows.
	 *
	 * Extracted from {@see isWithinSessionWindow()} so it stays within the
	 * complexity budget; behaviour is unchanged: unparsable/missing
	 * timestamps count as 0 and never win the max.
	 *
	 * @param array<int, mixed> $rows Inbound message rows.
	 *
	 * @return int Latest unix timestamp, or 0 when none parsed.
	 */
	private function latestInboundTimestamp(array $rows): int {
		$latestTimestamp = 0;
		foreach ($rows as $raw) {
			$arr = $this->toArray(value: $raw);
			$timestamp = strtotime((string)($arr['sentAt'] ?? ''));
			if ($timestamp === false) {
				$timestamp = 0;
			}

			if ($timestamp > $latestTimestamp) {
				$latestTimestamp = $timestamp;
			}
		}

		return $latestTimestamp;
	}//end latestInboundTimestamp()

	/**
	 * Provider list for an outbound send.
	 *
	 * When the template is bound to a specific provider, that
	 * provider goes first; any other active provider then acts as
	 * fallback (provided it also has the template approved — the
	 * caller already verified `approved` on the bound provider).
	 *
	 * @param string $templateProviderId Provider UUID bound to the
	 *                                   template (or empty).
	 *
	 * @return array<int, array<string, mixed>> Provider rows.
	 */
	private function orderedProvidersForSend(string $templateProviderId): array {
		$cloud = $this->providerRepo->listActive(kind: 'whatsapp-cloud-api');
		$bsp = $this->providerRepo->listActive(kind: 'whatsapp-bsp');
		$merged = array_merge($cloud, $bsp);

		if ($templateProviderId === '') {
			return $merged;
		}

		$pinned = [];
		$rest = [];
		foreach ($merged as $row) {
			if ($this->extractId(payload: $row) === $templateProviderId) {
				$pinned[] = $row;
				continue;
			}

			$rest[] = $row;
		}

		return array_merge($pinned, $rest);
	}//end orderedProvidersForSend()

	/**
	 * Persist + alert on full-failure.
	 *
	 * @param WhatsAppSendRun     $run     The send that failed.
	 * @param WhatsAppSendFailure $failure The provider it failed on and the error.
	 *
	 * @return array{status: string, error: string, providerId?: string} Outcome.
	 *
	 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	private function persistFailureAndAlert(WhatsAppSendRun $run, WhatsAppSendFailure $failure): array {
		$this->persistMessage(payload: $this->failedMessagePayload(run: $run, failure: $failure));
		$this->alertSendFailure(run: $run, failure: $failure);

		return [
			'status' => self::STATUS_FAILED,
			'error' => $failure->error,
			'providerId' => $failure->providerId,
		];
	}//end persistFailureAndAlert()

	/**
	 * The failed outbound message row, in the contact's WhatsApp conversation.
	 *
	 * @param WhatsAppSendRun     $run     The send that failed.
	 * @param WhatsAppSendFailure $failure The provider it failed on and the error.
	 *
	 * @return array<string, mixed> The message payload.
	 */
	private function failedMessagePayload(WhatsAppSendRun $run, WhatsAppSendFailure $failure): array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $run->message->contactId,
			providerId: $failure->providerId,
			channel: 'whatsapp',
		);

		return ['contactId' => $run->message->contactId, 'conversationId' => $conversationId]
			+ $this->failedMessageFields(run: $run, failure: $failure);
	}//end failedMessagePayload()

	/**
	 * The fields of a failed outbound message after its contact and conversation.
	 *
	 * The template parameters are kept so Send again can repeat a template send.
	 *
	 * @param WhatsAppSendRun     $run     The send that failed.
	 * @param WhatsAppSendFailure $failure The provider it failed on and the error.
	 *
	 * @return array<string, mixed> The message fields.
	 */
	private function failedMessageFields(WhatsAppSendRun $run, WhatsAppSendFailure $failure): array {
		return [
			'channel' => 'whatsapp',
			'direction' => 'outbound',
			'body' => $run->outboundBody(),
			'providerId' => $failure->providerId,
			'templateId' => $this->templateIdForSend(template: $run->templateSend->template),
			'templateParameters' => array_values($run->templateSend->parameters),
			'deliveryStatus' => 'failed',
			'sentAt' => $this->nowIso(),
			'metadata' => ['error' => $failure->error],
		];
	}//end failedMessageFields()

	/**
	 * Notify the admin of a failed send; a notification that fails is logged.
	 *
	 * @param WhatsAppSendRun     $run     The send that failed.
	 * @param WhatsAppSendFailure $failure The provider it failed on and the error.
	 *
	 * @return void
	 */
	private function alertSendFailure(WhatsAppSendRun $run, WhatsAppSendFailure $failure): void {
		try {
			$this->notifySendFailure(run: $run, failure: $failure);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.persistFailureAndAlert: notification failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end alertSendFailure()

	/**
	 * Send the admin the failed-send notification.
	 *
	 * @param WhatsAppSendRun     $run     The send that failed.
	 * @param WhatsAppSendFailure $failure The provider it failed on and the error.
	 *
	 * @return void
	 */
	private function notifySendFailure(WhatsAppSendRun $run, WhatsAppSendFailure $failure): void {
		$contactId = $run->message->contactId;
		$this->notificationService->sendNotification(
			userId: 'admin',
			subject: 'messaging_whatsapp_send_failed',
			parameters: ['contactId' => $contactId, 'providerId' => $failure->providerId, 'error' => $failure->error],
			objectType: 'message',
			objectId: $contactId,
		);
	}//end notifySendFailure()

	/**
	 * Persist the outbound message row of a provider attempt that went out.
	 *
	 * @param WhatsAppProviderAttempt $attempt           The attempt.
	 * @param string                  $externalMessageId Provider id.
	 *
	 * @return array<string, mixed>|null Saved row.
	 */
	private function persistOutbound(WhatsAppProviderAttempt $attempt, string $externalMessageId): ?array {
		$conversationId = $this->findOrOpenConversation(
			contactId: $attempt->run->message->contactId,
			providerId: $attempt->providerId,
			channel: 'whatsapp',
		);

		return $this->persistMessage(
			payload: [
				'contactId' => $attempt->run->message->contactId,
				'conversationId' => $conversationId,
				'channel' => 'whatsapp',
				'direction' => 'outbound',
				'body' => $attempt->run->outboundBody(),
				'providerId' => $attempt->providerId,
				'externalMessageId' => $externalMessageId,
				'templateId' => $this->templateIdForSend(template: $attempt->run->templateSend->template),
				'templateParameters' => array_values($attempt->run->templateSend->parameters),
				'deliveryStatus' => 'queued',
				'sentAt' => $this->nowIso(),
			],
		);
	}//end persistOutbound()

	/**
	 * Send the opt-out acknowledgement to the number that sent the STOP.
	 *
	 * Permitted under utility-category rules because it happens within the
	 * inbound's open session window. Best-effort: a failure does not break the
	 * opt-out, which is already recorded.
	 *
	 * @param string $phone Sender in E.164.
	 * @param array<string, mixed> $providerRow Provider row.
	 *
	 * @return void
	 */
	private function sendOptOutAcknowledgement(string $phone, array $providerRow): void {
		try {
			$body = (string)$this->appConfig->getValueString(
				AppIdentity::APP_ID,
				'whatsapp.opt_out_ack_body',
				'Bedankt. U bent uitgeschreven en ontvangt geen verdere berichten via dit kanaal.'
			);

			// We bypass consent here because the user just opted out:
			// ConsentService.canSend() would refuse. Use the provider
			// client directly with the open session.
			$this->providerClient->sendFreeForm(
				channelProvider: $providerRow,
				phoneNumber: $phone,
				body: $body,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'WhatsAppAdapter.sendOptOutAcknowledgement: best-effort failed',
				['exception' => $e->getMessage()]
			);
		}
	}//end sendOptOutAcknowledgement()

	/**
	 * Extract the message body and sender phone number from a Meta
	 * webhook payload.
	 *
	 * @param string $rawBody Raw body.
	 *
	 * @return array{from: string, body: string, raw: array<string, mixed>} Extracted.
	 */
	private function extractMetaMessage(string $rawBody): array {
		$decoded = json_decode($rawBody, true);
		if (is_array($decoded) === false) {
			return ['from' => '', 'body' => '', 'raw' => []];
		}

		// Meta envelope: entry[0].changes[0].value.messages[0].
		$entry = ($decoded['entry'][0] ?? []);
		$change = ($entry['changes'][0] ?? []);
		$value = ($change['value'] ?? []);
		$message = ($value['messages'][0] ?? null);

		if (is_array($message) === false) {
			// Fall back to flat shapes used by tests + BSPs.
			return [
				'from' => (string)($decoded['from'] ?? ''),
				'body' => (string)(($decoded['body'] ?? $decoded['text'] ?? '')),
				'raw' => $decoded,
			];
		}

		$body = (string)($message['text']['body'] ?? ($message['body'] ?? ''));
		return [
			'from' => (string)($message['from'] ?? ''),
			'body' => $body,
			'raw' => $decoded,
		];
	}//end extractMetaMessage()

	/**
	 * Find or open a conversation row for (contact, provider, channel).
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
				],
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.findOrOpenConversation: open failed',
				['exception' => $e->getMessage()]
			);
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $saved));
	}//end findOrOpenConversation()

	/**
	 * Refresh the conversation's lastInboundAt + windowExpiresAt.
	 *
	 * @param string $conversationId Conversation UUID.
	 * @param string $windowExpiresAt ISO 8601 expiry.
	 *
	 * Called only from the signed public webhook: the read is a lookup without
	 * RBAC, the save runs as the messaging account.
	 *
	 * @return void
	 */
	private function touchConversationWindow(string $conversationId, string $windowExpiresAt): void {
		if ($conversationId === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		$schema = $this->resolveSchemaSlug(key: 'conversation_schema', default: self::DEFAULT_CONVERSATION_SCHEMA_SLUG);

		try {
			$existing = $objectService->find(
				id: $conversationId,
				register: $this->getRegisterSlug(),
				schema: $schema,
				_rbac: self::LOOKUP_SCOPE['_rbac'],
				_multitenancy: self::LOOKUP_SCOPE['_multitenancy'],
			);
		} catch (Throwable $e) {
			return;
		}

		if ($existing === null) {
			return;
		}

		$payload = $this->toArray(value: $existing);
		$payload['lastInboundAt'] = $this->nowIso();
		$payload['windowExpiresAt'] = $windowExpiresAt;

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $schema,
				uuid: $conversationId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WhatsAppAdapter.touchConversationWindow: save failed',
				['conversationId' => $conversationId, 'exception' => $e->getMessage()]
			);
		}
	}//end touchConversationWindow()

	/**
	 * Persist a message payload.
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
				'WhatsAppAdapter.persistMessage: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end persistMessage()

	/**
	 * Load a messageTemplate by id.
	 *
	 * @param string $id Template UUID / slug.
	 *
	 * @return array<string, mixed>|null Row or null.
	 */
	private function loadTemplate(string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null || $id === '') {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->resolveSchemaSlug(key: 'messageTemplate_schema', default: self::DEFAULT_TEMPLATE_SCHEMA_SLUG),
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadTemplate()

	/**
	 * Extract a phone number from a contact row.
	 *
	 * @param array<string, mixed> $contact Contact row.
	 *
	 * @return string Phone number or empty.
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
	 * Tenant id (app-config; defaults to `default`).
	 *
	 * @return string Tenant id.
	 */
	private function getTenantId(): string {
		$tenantId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}//end getTenantId()

	/**
	 * App-config schema slug override.
	 *
	 * @param string $key App-config key.
	 * @param string $default Built-in default.
	 *
	 * @return string Slug.
	 */
	private function resolveSchemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
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
				'WhatsAppAdapter.getObjectService: OpenRegister unavailable',
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
		$id = $this->firstScalarId(source: $payload);
		if ($id !== '') {
			return $id;
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			return $this->firstScalarId(source: $payload['@self']);
		}

		return '';
	}//end extractId()

	/**
	 * First non-empty scalar value among `uuid` / `id` / `slug` keys.
	 *
	 * Extracted from {@see extractId()} so both the top-level and `@self`
	 * lookups share one implementation; behaviour is unchanged.
	 *
	 * @param array<string, mixed> $source Source array to probe.
	 *
	 * @return string The first matching value, or empty when none match.
	 */
	private function firstScalarId(array $source): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($source[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end firstScalarId()

	/**
	 * Current ISO 8601 UTC timestamp.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
