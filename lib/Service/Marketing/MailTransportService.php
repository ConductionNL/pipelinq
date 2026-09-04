<?php

/**
 * Pipelinq MailTransportService.
 *
 * Resolves the `mailTransport` a Blast sends through (instance mail server,
 * a sender's Mail account, or an OpenConnector-fronted bulk provider),
 * enforces its daily send limit, and dispatches one rendered delivery to the
 * matching {@see \OCA\Pipelinq\Service\Marketing\Transport\TransportInterface}
 * adapter. This is the code that used to live in
 * `BlastService::sendOneDelivery()` / `resolveConnectorSource()` /
 * `renderTemplate()` / the rate-limit helpers, extracted so `BlastService`
 * keeps only Blast lifecycle concerns (sendBlast, A/B, totals) and the two
 * files merge cleanly against the concurrent list-audiences branch, which
 * only touches `sendBlast()`.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport;
use OCA\Pipelinq\Service\Marketing\Transport\InstanceMailerTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCA\Pipelinq\Service\Marketing\Transport\SendResult;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MailTransportService: resolve + dispatch a blast delivery's transport.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Public repository-style helpers
 *  (resolveTransport, resolveRateLimit, sendOneDelivery) plus their private
 *  support methods are cohesive with the single responsibility of this class.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Transport resolution + daily-limit
 *  enforcement + adapter dispatch + persistence live together by design, matching
 *  BlastService's own precedent for the send pipeline it replaces; splitting would
 *  only scatter one send-orchestration concern across several files.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 13, threshold 13. Wires
 *  the three transport adapters plus OpenRegister/tracking/app-config collaborators
 *  a send-orchestration service genuinely needs.
 */
class MailTransportService {
	/**
	 * Default register slug used when no `register` app config value is set.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default MailTransport schema slug used when no `mailTransport_schema`
	 * app config value is set.
	 */
	private const DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG = 'mailTransport';

	/**
	 * Default BlastDelivery schema slug used when no `blastDelivery_schema`
	 * app config value is set.
	 */
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	/**
	 * Fallback rate limit (messages per second) when neither the transport
	 * nor an OpenConnector source declare one.
	 */
	private const DEFAULT_RATE_LIMIT_PER_SECOND = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param IMailer $mailer Nextcloud's own mailer (instance-mailer transport).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IMailer $mailer,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the `mailTransport` a Blast sends through.
	 *
	 * Resolution order: the transport named by `blast.transportId` when it
	 * exists and is active; else, for a Blast that predates this change
	 * (carries `connectorSourceId` directly and no `transportId`), a
	 * synthesised `provider`/`sendgrid` transport reproducing exactly the
	 * legacy send path; else the `mailTransport` marked `default = true`;
	 * else null.
	 *
	 * @param array<string, mixed> $blast The Blast payload.
	 *
	 * @return array<string, mixed>|null The resolved transport row, or null
	 *                                   when nothing can be resolved.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-blast-selects-its-transport-falling-back-to-the-default
	 */
	public function resolveTransport(array $blast): ?array {
		$transportId = (string)($blast['transportId'] ?? '');
		if ($transportId !== '') {
			$named = $this->loadOne(id: $transportId);
			if ($named !== null && (bool)($named['active'] ?? true) === true) {
				return $named;
			}
		}

		$legacySourceId = (string)($blast['connectorSourceId'] ?? '');
		if ($transportId === '' && $legacySourceId !== '') {
			return $this->legacyProviderTransport(connectorSourceId: $legacySourceId);
		}

		return $this->loadDefaultTransport();
	}//end resolveTransport()

	/**
	 * Resolve the effective rate limit (messages per second) for a transport.
	 *
	 * Only a `provider`-kind transport carries an OpenConnector source whose
	 * own `rateLimitLimit`/`rateLimitWindow` may cap the caller's rate;
	 * `instance`/`mailAccount` transports use the caller's rate (or the
	 * default) directly.
	 *
	 * @param array<string, mixed> $transport The resolved transport row.
	 * @param int $callerRate Caller's max-per-second.
	 *
	 * @return int Resolved rate limit (>=1).
	 *
	 * @spec openspec/specs/marketing-blast/spec.md#requirement-throttle-respects-provider-rate-limits
	 */
	public function resolveRateLimit(array $transport, int $callerRate): int {
		$candidate = self::DEFAULT_RATE_LIMIT_PER_SECOND;
		if ($callerRate > 0) {
			$candidate = $callerRate;
		}

		if ((string)($transport['kind'] ?? '') !== 'provider') {
			return max($candidate, 1);
		}

		$sourceRate = $this->readSourceRateLimit(connectorSourceId: (string)($transport['connectorSourceId'] ?? ''));
		if ($sourceRate !== null && $sourceRate > 0 && $sourceRate < $candidate) {
			return $sourceRate;
		}

		return max($candidate, 1);
	}//end resolveRateLimit()

	/**
	 * Render, track-inject, limit-check and send one BlastDelivery through
	 * the resolved transport, persisting the outcome.
	 *
	 * @param array<string, mixed> $delivery Queued BlastDelivery row.
	 * @param array<string, mixed> $template CampaignTemplate row.
	 * @param array<string, mixed> $transport Resolved `mailTransport` row.
	 *
	 * @return bool True when the transport accepted the message.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-transport-enforces-its-own-daily-send-limit
	 */
	public function sendOneDelivery(array $delivery, array $template, array $transport): bool {
		$transport = $this->rollDailyLimitIfNewDay(transport: $transport);
		if ($this->underDailyLimit(transport: $transport) === false) {
			$this->logger->info(
				'MailTransportService.sendOneDelivery: transport at its daily limit',
				['transportId' => $this->extractId(payload: $transport)]
			);
			$this->markFailed(delivery: $delivery);
			return false;
		}

		$mail = $this->buildRenderedMail(template: $template, delivery: $delivery);
		$result = $this->dispatchToAdapter(mail: $mail, transport: $transport);
		if ($result->accepted === false) {
			$this->logger->warning(
				'MailTransportService.sendOneDelivery: transport rejected the delivery',
				['deliveryId' => $mail->deliveryId, 'reason' => $result->error]
			);
			$this->markFailed(delivery: $delivery);
			return false;
		}

		$this->markSent(delivery: $delivery, providerId: $result->providerId);
		$this->advanceSentToday(transport: $transport);
		return true;
	}//end sendOneDelivery()

	/**
	 * Build the transport-agnostic {@see RenderedMail} from a template + delivery.
	 *
	 * Substitution is intentionally minimal — `{{email}}`, `{{contactId}}` —
	 * matching the pre-existing `BlastService::renderTemplate()` semantics.
	 * First-party tracking injection (when enabled) runs on the HTML body
	 * before the mail is handed to any transport.
	 *
	 * @param array<string, mixed> $template CampaignTemplate row.
	 * @param array<string, mixed> $delivery BlastDelivery row.
	 *
	 * @return RenderedMail
	 */
	private function buildRenderedMail(array $template, array $delivery): RenderedMail {
		$tokens = [
			'{{email}}' => (string)($delivery['email'] ?? ''),
			'{{contactId}}' => (string)($delivery['contactId'] ?? ''),
		];

		$html = strtr((string)($template['bodyHtml'] ?? ''), $tokens);
		$deliveryId = $this->extractId(payload: $delivery);
		if ($this->firstPartyTrackingEnabled() === true) {
			$html = $this->injectTrackingLinks(html: $html, blastDeliveryId: $deliveryId);
		}

		return new RenderedMail(
			fromEmail: (string)($template['senderEmail'] ?? ''),
			fromName: (string)($template['senderName'] ?? ''),
			replyTo: (string)($template['replyTo'] ?? ''),
			toEmail: (string)($delivery['email'] ?? ''),
			subject: strtr((string)($template['subject'] ?? ''), $tokens),
			html: $html,
			text: strtr((string)($template['bodyText'] ?? ''), $tokens),
			headers: [],
			deliveryId: $deliveryId,
		);
	}//end buildRenderedMail()

	/**
	 * Pick the adapter matching `transport.kind` and send.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 * @param array<string, mixed> $transport Resolved transport row.
	 *
	 * @return SendResult
	 */
	private function dispatchToAdapter(RenderedMail $mail, array $transport): SendResult {
		$kind = (string)($transport['kind'] ?? '');
		try {
			$adapter = match ($kind) {
				'instance' => new InstanceMailerTransport(mailer: $this->mailer, logger: $this->logger),
				'mailAccount' => new MailAccountTransport(
					container: $this->container,
					logger: $this->logger,
					mailAccountRef: (string)($transport['mailAccountRef'] ?? ''),
					mailAccountUserId: (string)($transport['mailAccountUserId'] ?? ''),
				),
				'provider' => new ConnectorSourceTransport(
					container: $this->container,
					logger: $this->logger,
					connectorSourceId: (string)($transport['connectorSourceId'] ?? ''),
					provider: (string)($transport['provider'] ?? ''),
				),
				default => null,
			};
		} catch (Throwable $e) {
			$this->logger->error(
				'MailTransportService.dispatchToAdapter: adapter construction failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'adapter-construction-failed');
		}

		if ($adapter === null) {
			$this->logger->error('MailTransportService.dispatchToAdapter: unknown transport kind', ['kind' => $kind]);
			return new SendResult(accepted: false, error: 'unknown-transport-kind');
		}

		return $adapter->send(mail: $mail);
	}//end dispatchToAdapter()

	/**
	 * Whether first-party open/click tracking is enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#3.2
	 */
	private function firstPartyTrackingEnabled(): bool {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'blast.first_party_tracking',
			'false',
		) === 'true';
	}//end firstPartyTrackingEnabled()

	/**
	 * Rewrite a rendered email body's links + append the open pixel via
	 * `TrackingLinkService::injectTracking()`. Resolved lazily; fails soft.
	 *
	 * @param string $html Rendered email body HTML.
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 *
	 * @return string The rewritten HTML, or the original on failure.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#3.2
	 */
	private function injectTrackingLinks(string $html, string $blastDeliveryId): string {
		if ($html === '' || $blastDeliveryId === '') {
			return $html;
		}

		try {
			$trackingLinkService = $this->container->get('OCA\\Pipelinq\\Service\\TrackingLinkService');
		} catch (Throwable $e) {
			$this->logger->info(
				'MailTransportService.injectTrackingLinks: TrackingLinkService unavailable',
				['exception' => $e->getMessage()]
			);
			return $html;
		}

		try {
			return $trackingLinkService->injectTracking(html: $html, blastDeliveryId: $blastDeliveryId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailTransportService.injectTrackingLinks: injection failed',
				['blastDeliveryId' => $blastDeliveryId, 'exception' => $e->getMessage()]
			);
			return $html;
		}
	}//end injectTrackingLinks()

	/**
	 * A synthesised `provider`/`sendgrid` transport row reproducing the
	 * pre-existing (pre-mailTransport) send path for a Blast that still
	 * carries `connectorSourceId` directly.
	 *
	 * @param string $connectorSourceId The Blast's legacy `connectorSourceId`.
	 *
	 * @return array<string, mixed>
	 */
	private function legacyProviderTransport(string $connectorSourceId): array {
		return [
			'kind' => 'provider',
			'provider' => 'sendgrid',
			'connectorSourceId' => $connectorSourceId,
			'dailyLimit' => 0,
			'sentToday' => 0,
			'active' => true,
		];
	}//end legacyProviderTransport()

	/**
	 * Load the `mailTransport` marked `default = true`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadDefaultTransport(): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getMailTransportSchemaSlug(),
						'default' => true,
					],
					'limit' => 1,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.loadDefaultTransport: findAll failed', ['exception' => $e->getMessage()]);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			return $this->toArray(value: $row);
		}

		return null;
	}//end loadDefaultTransport()

	/**
	 * Load one `mailTransport` by id.
	 *
	 * @param string $id MailTransport UUID or slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadOne(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info('MailTransportService.loadOne: not found', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		return $this->toArray(value: $found);
	}//end loadOne()

	/**
	 * Whether a transport is still under its `dailyLimit`.
	 *
	 * @param array<string, mixed> $transport The transport row.
	 *
	 * @return bool True when `dailyLimit` is 0 (no cap) or `sentToday` is below it.
	 */
	private function underDailyLimit(array $transport): bool {
		$dailyLimit = (int)($transport['dailyLimit'] ?? 0);
		if ($dailyLimit <= 0) {
			return true;
		}

		return (int)($transport['sentToday'] ?? 0) < $dailyLimit;
	}//end underDailyLimit()

	/**
	 * Roll `sentToday` to 0 when `dailyLimitResetAt` falls on an earlier
	 * calendar day than today, persisting the reset immediately so a
	 * concurrent dispatch sees the rolled counter too.
	 *
	 * @param array<string, mixed> $transport The transport row.
	 *
	 * @return array<string, mixed> The (possibly rolled) transport row.
	 */
	private function rollDailyLimitIfNewDay(array $transport): array {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			// Legacy synthesised transport (no persisted row) — nothing to roll.
			return $transport;
		}

		$resetAt = (string)($transport['dailyLimitResetAt'] ?? '');
		$today = gmdate('Y-m-d');
		if ($resetAt !== '' && substr($resetAt, 0, 10) === $today) {
			return $transport;
		}

		$transport['sentToday'] = 0;
		$transport['dailyLimitResetAt'] = $this->nowIso();
		$this->saveObject(payload: $transport, id: $id);
		return $transport;
	}//end rollDailyLimitIfNewDay()

	/**
	 * Advance `sentToday` by one and persist, on a successful send.
	 *
	 * @param array<string, mixed> $transport The transport row.
	 *
	 * @return void
	 */
	private function advanceSentToday(array $transport): void {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			// Legacy synthesised transport — nothing to persist.
			return;
		}

		$transport['sentToday'] = ((int)($transport['sentToday'] ?? 0) + 1);
		$this->saveObject(payload: $transport, id: $id);
	}//end advanceSentToday()

	/**
	 * Mark a BlastDelivery `sent`, storing the provider id when present.
	 *
	 * @param array<string, mixed> $delivery The delivery row.
	 * @param string|null $providerId The transport/provider message id.
	 *
	 * @return void
	 */
	private function markSent(array $delivery, ?string $providerId): void {
		$payload = $delivery;
		$payload['status'] = 'sent';
		$payload['sentAt'] = $this->nowIso();
		if ($providerId !== null && $providerId !== '') {
			$payload['providerId'] = $providerId;
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}//end markSent()

	/**
	 * Mark a BlastDelivery `failed` (never overwrites a terminal status set
	 * elsewhere; the caller decides whether to retry on a later dispatch).
	 *
	 * @param array<string, mixed> $delivery The delivery row.
	 *
	 * @return void
	 */
	private function markFailed(array $delivery): void {
		$payload = $delivery;
		$payload['status'] = 'failed';
		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}//end markFailed()

	/**
	 * Read the effective per-second rate limit from a `provider`-kind
	 * transport's OpenConnector source.
	 *
	 * @param string $connectorSourceId The source id.
	 *
	 * @return int|null Rate limit (messages/second), or null when unset/unresolvable.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential resolve/parse guard
	 *  clauses over the source lookup; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same flat guard sequence; path count is a
	 *  product of independent conditions, not nesting.
	 */
	private function readSourceRateLimit(string $connectorSourceId): ?int {
		if ($connectorSourceId === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$source = $objectService->find(id: $connectorSourceId, register: 'openconnector', schema: 'source');
		} catch (Throwable $e) {
			return null;
		}

		if ($source === null) {
			return null;
		}

		$sourceArray = $this->toArray(value: $source);
		$limitValue = ($sourceArray['rateLimitLimit'] ?? null);
		if ($limitValue === null || is_numeric($limitValue) === false) {
			return null;
		}

		$limit = (int)$limitValue;
		if ($limit <= 0) {
			return null;
		}

		$windowValue = ($sourceArray['rateLimitWindow'] ?? null);
		$window = 1;
		if ($windowValue !== null && is_numeric($windowValue) === true) {
			$window = (int)$windowValue;
		}

		if ($window <= 0) {
			$window = 1;
		}

		return max(1, (int)floor($limit / $window));
	}//end readSourceRateLimit()

	/**
	 * Persist a `mailTransport` row.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string $id The row's id.
	 *
	 * @return void
	 */
	private function saveObject(array $payload, string $id): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.saveObject: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}//end saveObject()

	/**
	 * Persist a `blastDelivery` row.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string $id The row's id.
	 *
	 * @return void
	 */
	private function saveDelivery(array $payload, string $id): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getBlastDeliverySchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.saveDelivery: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}//end saveDelivery()

	/**
	 * Resolve OpenRegister's `ObjectService` from the DI container.
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.getObjectService: unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()

	/**
	 * The configured `pipelinq` register slug.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * The configured `mailTransport` schema slug.
	 *
	 * @return string
	 */
	private function getMailTransportSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailTransport_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG;
	}//end getMailTransportSchemaSlug()

	/**
	 * The configured `blastDelivery` schema slug.
	 *
	 * @return string
	 */
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}//end getBlastDeliverySchemaSlug()

	/**
	 * Extract a UUID/id/slug from an OpenRegister payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return string The id, or '' when none is present.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($self[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Normalise an OpenRegister entity (or array) into a plain array.
	 *
	 * @param mixed $value The entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				return (array)$value->jsonSerialize();
			}

			if (method_exists($value, 'getObject') === true) {
				return (array)$value->getObject();
			}
		}

		return [];
	}//end toArray()

	/**
	 * Current UTC instant, ISO 8601.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
