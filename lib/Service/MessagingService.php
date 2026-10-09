<?php

/**
 * Pipelinq MessagingService.
 *
 * Server-side orchestration for the agent send surface (REQ-OM-003/004): it
 * loads the target contact (per-object guard), routes a send to the SMS /
 * WhatsApp adapter with the outbound-audit send context, sanitises the adapter
 * outcome so no raw vendor error or credential ever leaves the app, computes
 * the composer preflight facts (available channels, session-window state, per
 * channel consent, approved templates) and runs the zero-cost provider
 * connectivity test through OpenRegister's MessageDispatchProvider leaf. All
 * consent / budget / template / failover gating stays inside the adapters —
 * this service never talks to a provider directly and never decides compliance.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates the send / preflight / consent / provider-test surface.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the two adapters +
 *  consent + provider repository + OpenRegister into one send surface.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cohesive send-surface
 *  orchestration kept in small single-purpose methods.
 */
class MessagingService {
	/**
	 * FQCN of the OpenRegister dispatch leaf used for connectivity tests.
	 *
	 * @var string
	 */
	private const DISPATCH_LEAF_CLASS = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\MessageDispatchProvider';

	/**
	 * Vendor send paths keyed by OpenConnector source slug (connectivity test).
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_TEST_PATHS = [
		'messagebird-sms' => '/messages',
		'cmcom-sms' => '/v1.0/message',
		'twilio-sms' => '/2010-04-01/Accounts/test/Messages.json',
		'whatsapp-cloud-api' => '/messages',
		'whatsapp-bsp' => '/messages',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OR services).
	 * @param IAppConfig $appConfig App config.
	 * @param ChannelProviderRepository $providerRepo Provider read-side.
	 * @param SmsAdapter $smsAdapter SMS adapter.
	 * @param WhatsAppAdapter $whatsAppAdapter WhatsApp adapter.
	 * @param ConsentService $consentService Consent gate + records.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private SmsAdapter $smsAdapter,
		private WhatsAppAdapter $whatsAppAdapter,
		private ConsentService $consentService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load a contact / client object by id (per-object accessibility guard).
	 *
	 * Returns null when OpenRegister is absent, the register is unconfigured,
	 * or the object does not exist — the caller treats null as "not accessible"
	 * and refuses before any adapter is invoked (no-admin-idor).
	 *
	 * @param string $contactId Contact or client UUID.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function loadContact(string $contactId): ?array {
		if ($contactId === '') {
			return null;
		}

		$register = $this->registerSlug();
		if ($register === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		$schemas = [
			$this->schemaSlug(key: 'contact_schema', default: 'contact'),
			$this->schemaSlug(key: 'client_schema', default: 'client'),
		];

		foreach ($schemas as $schema) {
			try {
				$entity = $objectService->find(id: $contactId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				$entity = null;
			}

			if ($entity !== null) {
				return $this->toArray(value: $entity);
			}
		}

		return null;
	}//end loadContact()

	/**
	 * The channels that currently have at least one active provider.
	 *
	 * @return array{sms: bool, whatsapp: bool} Channel availability.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-003-agent-send-surface-on-client-and-contact-detail
	 */
	public function availableChannels(): array {
		$sms = ($this->providerRepo->listActive(kind: 'sms') !== []);
		$cloud = $this->providerRepo->listActive(kind: 'whatsapp-cloud-api');
		$bsp = $this->providerRepo->listActive(kind: 'whatsapp-bsp');
		$whatsapp = ($cloud !== [] || $bsp !== []);

		return ['sms' => $sms, 'whatsapp' => $whatsapp];
	}//end availableChannels()

	/**
	 * Composer preflight facts for one contact.
	 *
	 * @param string $contactId Contact UUID (already access-guarded by the caller).
	 *
	 * @return array{
	 *     channels: array{sms: bool, whatsapp: bool},
	 *     whatsappSessionOpen: bool,
	 *     consent: array{sms: string, whatsapp: string},
	 *     templates: array<int, array<string, mixed>>
	 * } Preflight facts.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function preflight(string $contactId): array {
		return [
			'channels' => $this->availableChannels(),
			'whatsappSessionOpen' => $this->whatsAppAdapter->isWithinSessionWindow(contactId: $contactId),
			'consent' => [
				'sms' => $this->consentService->latestState(contactId: $contactId, channel: 'sms'),
				'whatsapp' => $this->consentService->latestState(contactId: $contactId, channel: 'whatsapp'),
			],
			'templates' => $this->approvedTemplates(),
		];
	}//end preflight()

	/**
	 * Send a message, delegating all gating to the adapters (REQ-OM-004).
	 *
	 * @param array<string, mixed> $contact The pre-loaded contact object.
	 * @param string $channel `sms` or `whatsapp`.
	 * @param string $body Free-text body (sms / in-window whatsapp).
	 * @param string|null $templateId Approved template UUID (whatsapp) or null.
	 * @param array<int, string> $parameters Positional template parameters.
	 * @param string|null $providerHint Optional pinned vendor.
	 * @param string $actor Acting user id (audit).
	 * @param string $clientId Linked client UUID (audit), or empty.
	 * @param string $inReplyTo The contact's inbound message this answers; the adapter verifies it.
	 *
	 * @return array{status: string, messageId?: string, reason?: string} Sanitised outcome.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function send(
		array $contact,
		string $channel,
		string $body,
		?string $templateId,
		array $parameters,
		?string $providerHint,
		string $actor,
		string $clientId = '',
		string $inReplyTo = '',
	): array {
		$client = $clientId;
		if ($client === '') {
			$client = (string)($contact['client'] ?? '');
		}

		$context = ['agent' => $actor, 'clientId' => $client];
		if ($inReplyTo !== '') {
			$context['inReplyTo'] = $inReplyTo;
		}

		if ($channel === 'sms') {
			$outcome = $this->smsAdapter->send(
				contact: $contact,
				body: $body,
				providerHint: $providerHint,
				context: $context
			);
			return $this->sanitiseOutcome(outcome: $outcome);
		}

		if ($channel === 'whatsapp') {
			$outcome = $this->whatsAppAdapter->send(
				contact: $contact,
				body: $body,
				templateId: $templateId,
				parameters: $parameters,
				context: $context
			);
			return $this->sanitiseOutcome(outcome: $outcome);
		}

		return ['status' => 'unsupported-channel'];
	}//end send()

	/**
	 * Load one channelMessage row through the register RBAC.
	 *
	 * @param string $messageId The message UUID.
	 *
	 * @return array<string, mixed>|null The row, or null when absent or not readable.
	 *
	 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function loadMessage(string $messageId): ?array {
		$objectService = $this->objectService();
		if ($messageId === '' || $objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $messageId,
				register: $this->registerSlug(),
				schema: $this->messageSchema()
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadMessage()

	/**
	 * Why a message may not be sent again, or null when it may.
	 *
	 * @param array<string, mixed> $message The channelMessage row.
	 *
	 * @return string|null `inbound`, `not-failed`, `already-resent` or null.
	 *
	 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function resendRefusal(array $message): ?string {
		if (($message['direction'] ?? '') !== 'outbound') {
			return 'inbound';
		}

		if (in_array(($message['deliveryStatus'] ?? ''), ['failed', 'expired'], true) === false) {
			return 'not-failed';
		}

		$metadata = $message['metadata'] ?? [];
		if (is_array($metadata) === true && (string)($metadata['resentAs'] ?? '') !== '') {
			return 'already-resent';
		}

		return null;
	}//end resendRefusal()

	/**
	 * Send a failed message again through the normal send() and its checks.
	 *
	 * A template row repeats its template with the stored parameters; any
	 * other row repeats its text. On success the new row carries
	 * `metadata.resendOf` and the failed row `metadata.resentAs`; the failed
	 * row keeps its delivery status.
	 *
	 * @param array<string, mixed> $message The failed row (refusals already checked).
	 * @param array<string, mixed> $contact The contact, loaded through the RBAC.
	 * @param string $actor Acting user id.
	 *
	 * @return array{status: string, messageId?: string, reason?: string} Sanitised outcome.
	 *
	 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	public function resend(array $message, array $contact, string $actor): array {
		$channel = (string)($message['channel'] ?? '');
		$templateId = (string)($message['templateId'] ?? '');
		$body = '';
		$parameters = [];
		if ($channel !== 'whatsapp' || $templateId === '') {
			$templateId = '';
			$body = (string)($message['body'] ?? '');
		}

		if ($templateId !== '') {
			$parameters = array_values(array_map('strval', (array)($message['templateParameters'] ?? [])));
		}

		$outcome = $this->send(
			contact: $contact,
			channel: $channel,
			body: $body,
			templateId: ($templateId === '' ? null : $templateId),
			parameters: $parameters,
			providerHint: null,
			actor: $actor
		);

		$newId = (string)($outcome['messageId'] ?? '');
		if ($outcome['status'] === 'sent' && $newId !== '') {
			$this->linkResend(original: $message, newId: $newId);
		}

		return $outcome;
	}//end resend()

	/**
	 * Link a failed row and the row that sent it again, both ways.
	 *
	 * @param array<string, mixed> $original The failed row.
	 * @param string $newId The new row's id.
	 *
	 * @return void
	 */
	private function linkResend(array $original, string $newId): void {
		$originalId = (string)($original['id'] ?? ($original['uuid'] ?? ''));
		$this->mergeMetadata(row: $original, rowId: $originalId, fields: ['resentAs' => $newId]);

		$new = $this->loadMessage(messageId: $newId);
		if ($new !== null) {
			$this->mergeMetadata(row: $new, rowId: $newId, fields: ['resendOf' => $originalId]);
		}
	}//end linkResend()

	/**
	 * Save a message row with fields added to its metadata.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string $rowId Its id.
	 * @param array<string, string> $fields Metadata fields to add.
	 *
	 * @return void
	 */
	private function mergeMetadata(array $row, string $rowId, array $fields): void {
		$objectService = $this->objectService();
		if ($objectService === null || $rowId === '') {
			return;
		}

		$metadata = $row['metadata'] ?? [];
		if (is_array($metadata) === false) {
			$metadata = [];
		}

		$row['metadata'] = array_merge($metadata, $fields);
		try {
			$objectService->saveObject(
				object: $row,
				register: $this->registerSlug(),
				schema: $this->messageSchema(),
				uuid: $rowId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MessagingService.resend: could not link the resent message',
				['messageId' => $rowId, 'exception' => $e->getMessage()]
			);
		}
	}//end mergeMetadata()

	/**
	 * The channelMessage schema slug.
	 *
	 * @return string Slug.
	 */
	private function messageSchema(): string {
		return $this->schemaSlug(key: 'message_schema', default: 'channelMessage');
	}//end messageSchema()

	/**
	 * Run the zero-cost connectivity test for a provider through the OR leaf.
	 *
	 * For a mock-flagged source the leaf short-circuits and returns the canned
	 * vendor-shaped body (reachable + mock badge). A degraded leaf result
	 * surfaces the cause without any Throwable reaching the caller.
	 *
	 * @param array<string, mixed> $provider The channelProvider row.
	 *
	 * @return array{reachable: bool, mock?: bool, cause?: string} Test result.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-002-zero-cost-provider-connectivity-test
	 */
	public function runProviderTest(array $provider): array {
		$source = (string)($provider['sourceId'] ?? '');
		if ($source === '') {
			return ['reachable' => false, 'cause' => 'no-source-configured'];
		}

		if (class_exists(self::DISPATCH_LEAF_CLASS) === false) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		try {
			$leaf = $this->container->get(self::DISPATCH_LEAF_CLASS);
		} catch (Throwable $e) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		// Narrow with instanceof against the stubbed contract rather than method_exists:
		// the same runtime guard for a soft dependency (false, not a fatal, when
		// OpenRegister is absent) but one phpstan can narrow from, so the dispatch
		// call below is genuinely type-checked instead of resolving to stdClass.
		if (($leaf instanceof MessageDispatchProvider) === false) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		$path = (self::SOURCE_TEST_PATHS[$source] ?? '/messages');
		$body = ['validate' => true, 'recipients' => ['+31600000000'], 'body' => 'pipelinq connectivity test'];

		try {
			$result = $leaf->dispatch(source: $source, body: $body, path: $path, headers: []);
		} catch (Throwable $e) {
			$this->logger->warning('MessagingService.runProviderTest: dispatch threw', ['source' => $source, 'error' => $e->getMessage()]);
			return ['reachable' => false, 'cause' => 'dispatch-error'];
		}

		if (($result['unavailable'] ?? false) === true) {
			return ['reachable' => false, 'cause' => (string)($result['cause'] ?? 'unknown')];
		}

		$response = $result['response'] ?? [];
		return ['reachable' => true, 'mock' => $this->looksLikeMock(response: $response)];
	}//end runProviderTest()

	/**
	 * The inbound webhook URLs to paste into the provider console.
	 *
	 * @param string $providerId Provider UUID.
	 * @param string $kind Provider kind (`sms` / `whatsapp-*`).
	 *
	 * @return array<string, string> Channel → relative webhook path.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-001-messaging-provider-administration
	 */
	public function webhookUrls(string $providerId, string $kind): array {
		$urls = [];
		if ($kind === 'sms') {
			$urls['sms'] = '/apps/pipelinq/api/messaging-webhooks/sms/' . $providerId;
			return $urls;
		}

		$urls['whatsapp'] = '/apps/pipelinq/api/messaging-webhooks/whatsapp/' . $providerId;
		return $urls;
	}//end webhookUrls()

	/**
	 * Whether a leaf response body looks like a mock-mode canned response.
	 *
	 * @param mixed $response The leaf response payload.
	 *
	 * @return bool True when it carries a MOCK-flagged id.
	 */
	private function looksLikeMock(mixed $response): bool {
		$encoded = json_encode($response);
		if (is_string($encoded) === false) {
			return false;
		}

		return (stripos($encoded, 'mock') !== false);
	}//end looksLikeMock()

	/**
	 * The approved messageTemplate rows (for the composer template picker).
	 *
	 * @return array<int, array<string, mixed>> Approved templates.
	 */
	private function approvedTemplates(): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->registerSlug();
		if ($register === '') {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'status' => 'approved',
						'register' => $register,
						'schema' => $this->schemaSlug(key: 'messageTemplate_schema', default: 'messageTemplate'),
					],
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			$out[] = [
				'id' => (string)($arr['uuid'] ?? ($arr['id'] ?? '')),
				'externalId' => (string)($arr['externalId'] ?? ''),
				'language' => (string)($arr['language'] ?? ''),
				'body' => (string)($arr['body'] ?? ''),
			];
		}

		return $out;
	}//end approvedTemplates()

	/**
	 * Map a raw adapter outcome onto a client-safe envelope (no vendor text).
	 *
	 * @param array<string, mixed> $outcome Raw adapter outcome.
	 *
	 * @return array{status: string, messageId?: string, reason?: string} Sanitised.
	 */
	private function sanitiseOutcome(array $outcome): array {
		$status = (string)($outcome['status'] ?? '');

		if ($status === 'sent') {
			return ['status' => 'sent', 'messageId' => (string)($outcome['messageId'] ?? '')];
		}

		if ($status === 'consentMissing') {
			return ['status' => 'consent-missing'];
		}

		if ($status === 'budgetExceeded') {
			return ['status' => 'budget-exceeded'];
		}

		if ($status === 'sessionWindowExpired') {
			return ['status' => 'template-required'];
		}

		if ($status === 'noProviderAvailable') {
			return ['status' => 'no-provider'];
		}

		if (in_array($status, ['templateNotFound', 'templateNotApproved', 'templateParameterMismatch'], true) === true) {
			return ['status' => 'template-invalid', 'reason' => $status];
		}

		return ['status' => 'failed'];
	}//end sanitiseOutcome()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null Service or null.
	 */
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}//end objectService()

	/**
	 * Normalise an OR entity to a plain array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * The pipelinq register slug (app-config overridable).
	 *
	 * Fails closed on a blanked override. Passing the built-in slug as
	 * getValueString()'s default only covers an ABSENT key: a key that is
	 * present but set to an empty string returns '' verbatim, and none of the
	 * callers here check for that. An empty register is not the same as "no
	 * register" to OpenRegister — ObjectService skips setRegister() for an
	 * empty value, so the query silently inherits whatever register context an
	 * earlier call in the same request left on the shared service instance.
	 * Normalising '' back to the built-in slug, exactly as the sibling
	 * schemaSlug() already does, keeps every call scoped.
	 *
	 * @return string Slug; never empty.
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'pipelinq';
	}//end registerSlug()

	/**
	 * Resolve a schema slug app-config override.
	 *
	 * @param string $key App-config key.
	 * @param string $default Built-in default.
	 *
	 * @return string Slug.
	 */
	private function schemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}//end schemaSlug()
}//end class
