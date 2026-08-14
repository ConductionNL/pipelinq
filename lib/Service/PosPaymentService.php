<?php

/**
 * Pipelinq PosPaymentService.
 *
 * Orchestrates POS payment operations across the four provider adapters
 * (Mollie / CCV / Adyen / Stripe): credential storage with encryption-at-
 * rest via ICrypto, payment initiation/capture/refund routing, webhook
 * validation + settlement updates, idempotency keyed on the provider's
 * event id, and CloudEvent emission to Shillinq on settle/refund. The
 * service is the single trust boundary — adapter implementations only see
 * decrypted secrets in-memory during a single API call.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\BrokerHttpTransport;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POS payment orchestration service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  payment orchestrator legitimately needs: 4 adapter classes, OR container
 *  for ObjectService + WebhookService, ICrypto, IAppConfig, IGroupManager,
 *  logger. Splitting them would scatter one transactional concern.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole POS payment lifecycle (config CRUD + adapter resolve + initiate +
 *  capture + refund + webhook validate + settlement + idempotency + 2 event
 *  emitters) as many small, single-purpose methods. The cohesion is
 *  intentional.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface mirrors
 *  REQ-PAY-001 through REQ-PAY-011 — one method per requirement.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same single-responsibility
 *  scope as ExcessiveClassComplexity above — many short, focused methods.
 * @SuppressWarnings(PHPMD.TooManyMethods)           34 small single-purpose
 *  methods (CRUD + adapters + webhook + events + accessors); splitting would
 *  scatter one transactional concern across multiple classes.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
 */
class PosPaymentService {
	/**
	 * IAppConfig key prefix for stored credentials.
	 *
	 * @var string
	 */
	public const CONFIG_PREFIX = 'payment_provider.';

	/**
	 * CloudEvent type emitted on settlement.
	 *
	 * @var string
	 */
	public const EVENT_SETTLED = 'pipelinq.PosPayment.settled';

	/**
	 * CloudEvent type emitted on refund.
	 *
	 * @var string
	 */
	public const EVENT_REFUNDED = 'pipelinq.PosPayment.refunded';

	/**
	 * CloudEvent source identifier for payment events.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = '/apps/pipelinq/pos/payment';

	/**
	 * Provider names supported by this app.
	 *
	 * @var array<int, string>
	 */
	public const PROVIDERS = ['mollie', 'ccv', 'adyen', 'stripe'];

	/**
	 * Sensitive credential field names (encrypted at rest, masked in responses).
	 *
	 * `apiKey` and `apiSecret` are GONE from this list. They used to be encrypted here
	 * and decrypted into memory on every call, which is good hygiene but not custody:
	 * Pipelinq could read the key that moves the money, so Pipelinq was the trust
	 * boundary. The outbound call now goes through OpenRegister's credential broker,
	 * which holds the key and injects it server-side — see {@see BrokerHttpTransport}
	 * and `credentialId` below. `RemoveLegacyPspKeys` deletes any that were stored
	 * before this release.
	 *
	 * `webhookSecret` STAYS. It verifies an HMAC on an INBOUND webhook — a local verify
	 * operation, not an outbound request header — so a constrained HTTP proxy cannot
	 * carry it. It remains app-held until the broker grows a sign/verify capability.
	 *
	 * @var array<int, string>
	 */
	public const SENSITIVE_FIELDS = ['webhookSecret'];

	/**
	 * Credential-field names that used to be stored here and no longer may be.
	 *
	 * Kept only so the repair step and the update path can recognise — and refuse —
	 * them. Nothing reads their values.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED_SECRET_FIELDS = ['apiKey', 'apiSecret'];

	/**
	 * Mask used for sensitive fields in API responses (never the actual value).
	 *
	 * @var string
	 */
	public const MASK = '***SET***';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (OR services).
	 * @param IAppConfig $appConfig App configuration.
	 * @param ICrypto $crypto Encryption service (webhookSecret only —
	 *                        the PSP keys live in the broker now).
	 * @param IGroupManager $groupMgr Group manager for refund authorization.
	 * @param IUserSession $userSession Current session — the broker's ownership
	 *                                  guard needs an identity to check the
	 *                                  credential against.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private IGroupManager $groupMgr,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	// ---------------------------------------------------------------------
	// Provider configuration CRUD (REQ-PAY-002 / REQ-PAY-007).
	// ---------------------------------------------------------------------

	/**
	 * List all providers with masked credentials.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function listProviders(): array {
		$providers = [];
		foreach (self::PROVIDERS as $name) {
			$providers[] = $this->getProviderConfig(name: $name, includeMaskedSecrets: true);
		}

		return $providers;
	}//end listProviders()

	/**
	 * Get a single provider config (credentials masked).
	 *
	 * @param string $name The provider name.
	 * @param bool $includeMaskedSecrets When true, sensitive fields are returned as MASK if set, '' if not.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) toggles whether sensitive
	 *  fields are present at all (mask vs omit), not a behavioural branch
	 *  worth splitting into two public methods.
	 */
	public function getProviderConfig(string $name, bool $includeMaskedSecrets = true): array {
		$this->assertKnownProvider(name: $name);

		$config = [
			'name' => $name,
			'displayName' => $this->displayNameFor(name: $name),
			'type' => $this->typeFor(name: $name),
			'isActive' => $this->readBool(name: $name, key: 'isActive', default: false),
			'environment' => $this->readString(name: $name, key: 'environment', default: 'sandbox'),
			'testMode' => $this->readBool(name: $name, key: 'testMode', default: true),
			'config' => $this->readObject(name: $name, key: 'config'),
			'lastTestedAt' => $this->readString(name: $name, key: 'lastTestedAt', default: ''),
			'testResult' => $this->readObject(name: $name, key: 'testResult'),
			// Not masked: a credential UUID is a reference, not a secret. The admin UI
			// needs it back to show which credential is selected.
			'credentialId' => $this->readString(name: $name, key: 'credentialId', default: ''),
		];

		if ($includeMaskedSecrets === true) {
			foreach (self::SENSITIVE_FIELDS as $field) {
				$stored = $this->readString(name: $name, key: $field, default: '');
				$config[$field] = '';
				if ($stored !== '') {
					$config[$field] = self::MASK;
				}
			}
		}

		return $config;
	}//end getProviderConfig()

	/**
	 * Update a provider configuration (admin-only — controller enforces).
	 *
	 * Sensitive fields are encrypted before storage; non-sensitive scalars
	 * and the config object are stored verbatim. The MASK value is ignored
	 * so an admin saving the form without re-entering the secret does not
	 * blank it.
	 *
	 * @param string $name The provider name.
	 * @param array<string, mixed> $data The form payload.
	 *
	 * @return array<string, mixed> The updated, masked config.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function updateProvider(string $name, array $data): array {
		$this->assertKnownProvider(name: $name);

		$this->writeNonSensitiveFields(name: $name, data: $data);
		$this->writeSensitiveFields(name: $name, data: $data);

		return $this->getProviderConfig(name: $name, includeMaskedSecrets: true);
	}//end updateProvider()

	/**
	 * Store the non-sensitive scalar/object fields of a provider update.
	 *
	 * @param string $name The provider name.
	 * @param array<string, mixed> $data The form payload.
	 *
	 * @return void
	 */
	private function writeNonSensitiveFields(string $name, array $data): void {
		if (array_key_exists('isActive', $data) === true) {
			$this->writeBool(name: $name, key: 'isActive', value: (bool)$data['isActive']);
		}

		if (array_key_exists('environment', $data) === true) {
			$env = (string)$data['environment'];
			if ($env === 'live' || $env === 'sandbox') {
				$this->writeString(name: $name, key: 'environment', value: $env);
			}
		}

		if (array_key_exists('testMode', $data) === true) {
			$this->writeBool(name: $name, key: 'testMode', value: (bool)$data['testMode']);
		}

		if (array_key_exists('config', $data) === true && is_array($data['config']) === true) {
			$this->writeObject(name: $name, key: 'config', value: $data['config']);
		}

		// The broker credential UUID is a REFERENCE, not a secret: the key behind it
		// lives in the vault and is injected server-side, so this app cannot read it.
		// It is therefore stored and returned in the clear, and is not masked.
		if (array_key_exists('credentialId', $data) === true) {
			$this->writeString(name: $name, key: 'credentialId', value: (string)$data['credentialId']);
		}
	}//end writeNonSensitiveFields()

	/**
	 * Encrypt + store the sensitive credential fields of a provider update.
	 *
	 * Skips fields that are absent, empty, or still carrying the MASK
	 * sentinel (admin saved the form without re-entering the secret).
	 *
	 * @param string $name The provider name.
	 * @param array<string, mixed> $data The form payload.
	 *
	 * @return void
	 */
	private function writeSensitiveFields(string $name, array $data): void {
		// Refuse the retired fields outright. A client that still POSTs an apiKey must
		// not have it quietly persisted — that is precisely the custody we just removed.
		foreach (self::RETIRED_SECRET_FIELDS as $retired) {
			if (array_key_exists($retired, $data) === true) {
				$this->logger->warning(
					'Pipelinq POS payment: a retired credential field was submitted and ignored. '
					. 'PSP keys live in the credential broker now; set `credentialId` instead.',
					[
						'provider' => $name,
						'field' => $retired,
					]
				);
			}
		}

		foreach (self::SENSITIVE_FIELDS as $field) {
			if (array_key_exists($field, $data) === false) {
				continue;
			}

			$raw = (string)$data[$field];
			if ($raw === '' || $raw === self::MASK) {
				// Empty or masked — leave the stored value untouched.
				continue;
			}

			$encrypted = $this->crypto->encrypt($raw);
			$this->writeString(name: $name, key: $field, value: $encrypted);
			// Discard plaintext immediately (best-effort).
			unset($raw);
		}
	}//end writeSensitiveFields()

	/**
	 * Resolve an active provider adapter by name.
	 *
	 * @param string $name The provider name.
	 *
	 * @return PaymentProviderInterface
	 *
	 * @throws OCSBadRequestException When the provider is unknown.
	 * @throws OCSNotFoundException When the provider is not configured or inactive.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
	 */
	public function getPaymentProvider(string $name): PaymentProviderInterface {
		$this->assertKnownProvider(name: $name);

		// Fail closed on both halves. Without the broker, or without a credential, there
		// is no key to call the PSP with — and deliberately no app-held fallback to reach
		// for, because that fallback is the thing this change removes.
		if (BrokerHttpTransport::isAvailable() === false) {
			throw new OCSNotFoundException(
				'Payment provider ' . $name . ' cannot be used: the OpenRegister credential broker is not available.'
			);
		}

		$credentialId = $this->readString(name: $name, key: 'credentialId', default: '');
		if ($credentialId === '') {
			throw new OCSNotFoundException(
				'Payment provider ' . $name . ' has no credential. Select one from the credential broker in the '
				. 'POS payment settings.'
			);
		}

		// The adapter is handed NO apiKey. `webhookSecret` still comes through because it
		// verifies inbound webhook HMACs locally — the broker cannot do a verify op.
		$credentials = $this->resolveDecryptedCredentials(name: $name);

		$config = [
			'environment' => $this->readString(name: $name, key: 'environment', default: 'sandbox'),
			'testMode' => $this->readBool(name: $name, key: 'testMode', default: true),
		];

		$providerConfig = $this->readObject(name: $name, key: 'config');
		foreach ($providerConfig as $key => $value) {
			if (is_string($key) === true) {
				$config[$key] = $value;
			}
		}

		$adapter = $this->instantiateAdapter(name: $name, credentials: $credentials, config: $config);
		$adapter->setHttpTransport(
			new BrokerHttpTransport(
				credentialId: $credentialId,
				logger: $this->logger,
				actingUserId: $this->currentUid()
			)
		);

		return $adapter;
	}//end getPaymentProvider()

	/**
	 * The calling user's UID, when there is a session.
	 *
	 * The broker's ownership guard needs an identity. On the webhook/background path
	 * there is no session, and the credential owner must come from elsewhere — today
	 * that means those paths cannot make brokered outbound calls, which is correct:
	 * a webhook should verify its HMAC and enqueue, not call back out.
	 *
	 * @return string|null The UID, or null when there is no session.
	 */
	private function currentUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUid()

	// ---------------------------------------------------------------------
	// Payment lifecycle (REQ-PAY-003 / REQ-PAY-004 / REQ-PAY-005).
	// ---------------------------------------------------------------------

	/**
	 * Initiate a payment for a transaction.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $providerName The provider name.
	 * @param string $paymentMethod The method (ideal, card, ...).
	 *
	 * @return array<string, mixed> { transaction, payment: { sessionId, redirectUrl, status } }
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 * @throws OCSBadRequestException When provider or amount is invalid.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function initiatePayment(string $transactionId, string $providerName, string $paymentMethod): array {
		$transaction = $this->loadTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status !== 'confirmed') {
			throw new OCSBadRequestException('Transaction is not confirmed');
		}

		$provider = $this->getPaymentProvider(name: $providerName);

		$amount = (float)($transaction['total'] ?? 0);
		$result = $provider->initiate(
			transactionData: $transaction,
			amount: $amount,
			paymentMethod: $paymentMethod
		);

		if ($result['status'] === 'failed') {
			// Provider failure — DO NOT mutate the transaction state.
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: [
				'paymentProvider' => $providerName,
				'paymentSessionId' => $result['sessionId'],
				'paymentStatus' => 'pending',
				'paymentMethod' => $paymentMethod,
			]
		);

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}//end initiatePayment()

	/**
	 * Capture an authorized payment.
	 *
	 * @param string $transactionId The transaction id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	public function capturePayment(string $transactionId): array {
		$transaction = $this->loadTransaction(id: $transactionId);
		$sessionId = (string)($transaction['paymentSessionId'] ?? '');
		$providerName = (string)($transaction['paymentProvider'] ?? '');

		if ($providerName === '' || $sessionId === '') {
			throw new OCSBadRequestException('No payment session to capture');
		}

		$provider = $this->getPaymentProvider(name: $providerName);
		$result = $provider->capture(sessionId: $sessionId);

		if ($result['status'] === 'failed') {
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$newStatus = $result['status'];
		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: ['paymentStatus' => $newStatus]
		);

		if ($newStatus === 'settled') {
			$this->emitSettledEvent(transaction: $updated);
		}

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}//end capturePayment()

	/**
	 * Refund a settled (or captured) payment — manager only.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $reason The refund reason.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OCSForbiddenException When the caller is not a POS manager.
	 * @throws OCSBadRequestException When the transaction is not refundable.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	public function refundPayment(string $transactionId, string $reason, string $userId): array {
		if ($this->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Manager permission required');
		}

		$transaction = $this->loadTransaction(id: $transactionId);
		$paymentStatus = (string)($transaction['paymentStatus'] ?? '');
		if ($paymentStatus !== 'settled' && $paymentStatus !== 'captured') {
			throw new OCSBadRequestException('Transaction payment not settled');
		}

		$providerName = (string)($transaction['paymentProvider'] ?? '');
		$sessionId = (string)($transaction['paymentSessionId'] ?? '');
		if ($providerName === '' || $sessionId === '') {
			throw new OCSBadRequestException('No payment session to refund');
		}

		$provider = $this->getPaymentProvider(name: $providerName);
		$result = $provider->refund(sessionId: $sessionId, reason: $reason);

		if ($result['status'] === 'failed') {
			return [
				'transaction' => $transaction,
				'payment' => $result,
			];
		}

		$updated = $this->saveTransaction(
			id: $transactionId,
			patch: [
				'paymentStatus' => 'refunded',
				'refundReason' => $reason,
				'refundedAt' => $this->nowIso(),
			]
		);

		$this->emitRefundedEvent(
			transaction: $updated,
			refundId: $result['refundId'],
			reason: $reason
		);

		return [
			'transaction' => $updated,
			'payment' => $result,
		];
	}//end refundPayment()

	// ---------------------------------------------------------------------
	// Webhook handling (REQ-PAY-006 / REQ-PAY-011).
	// ---------------------------------------------------------------------

	/**
	 * Validate + route a provider webhook.
	 *
	 * Signature failure returns `{ status: invalid }` → controller maps to
	 * STATUS_BAD_REQUEST (gate-9 webhook convention).
	 *
	 * The status vocabulary is the controller's HTTP contract, so each value
	 * states whether the delivery was consumed:
	 *   - `ok` / `duplicate` — consumed, answered 2xx, provider stops.
	 *   - `invalid`          — the delivery is not ours (bad signature or an
	 *                          unknown provider); 400, never retried.
	 *   - `ignored`          — well-formed but carries no session id, so there
	 *                          is nothing to match and a redelivery of the same
	 *                          bytes can never succeed; 2xx by design.
	 *   - `unmatched`        — signed, carries a session id, and nothing was
	 *                          persisted. MUST be answered with a retryable
	 *                          5xx (pipelinq#799).
	 *
	 * @param string $providerName The provider name.
	 * @param string $rawPayload The raw request body.
	 * @param string $signature The signature header value.
	 *
	 * @return array<string, mixed> { status: ok|invalid|ignored|unmatched|duplicate, transactionId?, error? }
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	public function handleWebhook(string $providerName, string $rawPayload, string $signature): array {
		if (in_array(needle: $providerName, haystack: self::PROVIDERS, strict: true) === false) {
			return [
				'status' => 'invalid',
				'error' => 'Unknown provider',
			];
		}

		try {
			$provider = $this->getPaymentProvider(name: $providerName);
		} catch (Throwable $e) {
			return [
				'status' => 'invalid',
				'error' => 'Provider not configured',
			];
		}

		if ($provider->validateWebhook(rawPayload: $rawPayload, signature: $signature) === false) {
			$this->logger->warning(
				'Pipelinq POS payment: invalid webhook signature',
				['provider' => $providerName]
			);
			return [
				'status' => 'invalid',
				'error' => 'Signature mismatch',
			];
		}

		$decoded = [];
		if ($rawPayload !== '') {
			$maybe = json_decode($rawPayload, true);
			if (is_array($maybe) === true) {
				$decoded = $maybe;
			}
		}

		$envelope = $provider->parseWebhook(payload: $decoded);
		$sessionId = $envelope['sessionId'];
		$eventId = $envelope['eventId'];
		$status = $envelope['status'];

		if ($sessionId === '') {
			// Unknown payload — log + 200 so providers don't retry forever.
			$this->logger->info(
				'Pipelinq POS payment: webhook with no sessionId',
				['provider' => $providerName]
			);
			return ['status' => 'ignored'];
		}

		return $this->handleSettlement(
			providerName: $providerName,
			sessionId: $sessionId,
			paymentStatus: $status,
			eventId: $eventId
		);
	}//end handleWebhook()

	/**
	 * Handle a validated settlement update.
	 *
	 * @param string $providerName The provider name.
	 * @param string $sessionId The provider session id.
	 * @param string $paymentStatus The normalised lifecycle status.
	 * @param string $eventId The provider event id (idempotency key).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
	 */
	public function handleSettlement(string $providerName, string $sessionId, string $paymentStatus, string $eventId): array {
		$transaction = $this->findTransactionBySessionId(sessionId: $sessionId);
		if ($transaction === null) {
			// NOT `ignored`. The provider sent a signed settlement for a
			// session this instance cannot match, so nothing was persisted.
			// `unmatched` is answered with a retryable 5xx by the controller
			// so the provider redelivers; a 2xx here tells the provider the
			// settlement is booked and the money is lost (pipelinq#799).
			$this->logger->error(
				'Pipelinq POS payment: webhook for unmatched transaction; asking the provider to retry',
				[
					'provider' => $providerName,
					'session' => $sessionId,
				]
			);
			return ['status' => 'unmatched'];
		}

		// Idempotency — last processed event id stored on the transaction.
		$lastEventId = (string)($transaction['paymentWebhookEventId'] ?? '');
		if ($eventId !== '' && $lastEventId === $eventId) {
			return [
				'status' => 'duplicate',
				'transactionId' => (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? '')),
			];
		}

		$previousStatus = (string)($transaction['paymentStatus'] ?? '');
		$patch = $this->buildSettlementPatch(
			paymentStatus: $paymentStatus,
			previousStatus: $previousStatus,
			eventId: $eventId
		);

		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));
		$updated = $this->saveTransaction(id: $id, patch: $patch);

		$this->emitSettlementEvents(
			paymentStatus: $paymentStatus,
			previousStatus: $previousStatus,
			transaction: $updated,
			eventId: $eventId
		);

		return [
			'status' => 'ok',
			'transactionId' => $id,
		];
	}//end handleSettlement()

	/**
	 * Build the transaction patch for a settlement update.
	 *
	 * @param string $paymentStatus The normalised lifecycle status.
	 * @param string $previousStatus The transaction's status before this update.
	 * @param string $eventId The provider event id (idempotency key).
	 *
	 * @return array<string, mixed>
	 */
	private function buildSettlementPatch(string $paymentStatus, string $previousStatus, string $eventId): array {
		$patch = [
			'paymentStatus' => $paymentStatus,
			'paymentWebhookEventId' => $eventId,
		];

		if ($paymentStatus === 'settled' && $previousStatus !== 'settled') {
			$patch['settledAt'] = $this->nowIso();
		}

		if ($paymentStatus === 'refunded' && $previousStatus !== 'refunded') {
			$patch['refundedAt'] = $this->nowIso();
		}

		return $patch;
	}//end buildSettlementPatch()

	/**
	 * Emit the settled/refunded CloudEvent for a settlement update, if applicable.
	 *
	 * @param string $paymentStatus The normalised lifecycle status.
	 * @param string $previousStatus The transaction's status before this update.
	 * @param array<string, mixed> $transaction The updated transaction.
	 * @param string $eventId The provider event id (used as refundId on refund).
	 *
	 * @return void
	 */
	private function emitSettlementEvents(string $paymentStatus, string $previousStatus, array $transaction, string $eventId): void {
		if ($paymentStatus === 'settled' && $previousStatus !== 'settled') {
			$this->emitSettledEvent(transaction: $transaction);
		}

		if ($paymentStatus === 'refunded' && $previousStatus !== 'refunded') {
			$this->emitRefundedEvent(transaction: $transaction, refundId: $eventId, reason: 'Provider-initiated refund');
		}
	}//end emitSettlementEvents()

	/**
	 * Test the configured connection for a provider.
	 *
	 * @param string $name The provider name.
	 *
	 * @return array{status: string, message: string}
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function testConnection(string $name): array {
		$this->assertKnownProvider(name: $name);

		try {
			$provider = $this->getPaymentProvider(name: $name);
		} catch (Throwable $e) {
			return [
				'status' => 'error',
				'message' => 'Provider niet geconfigureerd',
			];
		}

		$result = $provider->testConnection();
		$this->writeString(name: $name, key: 'lastTestedAt', value: $this->nowIso());
		$this->writeObject(name: $name, key: 'testResult', value: $result);

		return $result;
	}//end testConnection()

	// ---------------------------------------------------------------------
	// CloudEvent emission (REQ-PAY-011).
	// ---------------------------------------------------------------------

	/**
	 * Emit the `pipelinq.PosPayment.settled` CloudEvent.
	 *
	 * @param array<string, mixed> $transaction The settled transaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
	 */
	public function emitSettledEvent(array $transaction): void {
		$payload = $this->buildSettledPayload(transaction: $transaction);
		$this->dispatch(eventName: self::EVENT_SETTLED, payload: $payload);
	}//end emitSettledEvent()

	/**
	 * Emit the `pipelinq.PosPayment.refunded` CloudEvent.
	 *
	 * @param array<string, mixed> $transaction The refunded transaction.
	 * @param string $refundId The provider refund id.
	 * @param string $reason The refund reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
	 */
	public function emitRefundedEvent(array $transaction, string $refundId, string $reason): void {
		$payload = $this->buildRefundedPayload(transaction: $transaction, refundId: $refundId, reason: $reason);
		$this->dispatch(eventName: self::EVENT_REFUNDED, payload: $payload);
	}//end emitRefundedEvent()

	// ---------------------------------------------------------------------
	// Internals — provider registry.
	// ---------------------------------------------------------------------

	/**
	 * Assert the provider name is one of the known providers.
	 *
	 * @param string $name The name.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException
	 */
	private function assertKnownProvider(string $name): void {
		if (in_array(needle: $name, haystack: self::PROVIDERS, strict: true) === false) {
			throw new OCSBadRequestException('Provider ' . $name . ' is not configured');
		}
	}//end assertKnownProvider()

	/**
	 * Instantiate the concrete adapter for a provider.
	 *
	 * @param string $name The provider name.
	 * @param array<string, string> $credentials The decrypted credentials.
	 * @param array<string, mixed> $config The merged config.
	 *
	 * @return PaymentProviderInterface
	 */
	private function instantiateAdapter(string $name, array $credentials, array $config): PaymentProviderInterface {
		return match ($name) {
			'mollie' => new MollieAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'ccv' => new CcvAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'adyen' => new AdyenAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			'stripe' => new StripeAdapter(credentials: $credentials, config: $config, logger: $this->logger),
			default => throw new OCSBadRequestException('Provider ' . $name . ' is not configured'),
		};
	}//end instantiateAdapter()

	/**
	 * Resolve decrypted credentials for a provider.
	 *
	 * @param string $name The provider name.
	 *
	 * @return array<string, string>
	 */
	private function resolveDecryptedCredentials(string $name): array {
		// The adapters' seventeen `if ($apiKey === '')` guards still expect a non-empty
		// credential. They get a clearly-labelled placeholder, not a key — the real one is
		// in the vault and the broker injects it. BrokerHttpTransport strips the auth
		// header the adapter builds from this, and CurlHttpTransport refuses to send any
		// request still carrying it, so it cannot reach the wire.
		$out = [];
		foreach (self::RETIRED_SECRET_FIELDS as $field) {
			$out[$field] = AbstractPaymentAdapter::BROKER_MANAGED_SECRET;
		}

		foreach (self::SENSITIVE_FIELDS as $field) {
			$encrypted = $this->readString(name: $name, key: $field, default: '');
			if ($encrypted === '') {
				$out[$field] = '';
				continue;
			}

			try {
				$out[$field] = $this->crypto->decrypt($encrypted);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq POS payment: failed to decrypt credential',
					[
						'provider' => $name,
						'field' => $field,
					]
				);
				$out[$field] = '';
			}//end try
		}

		return $out;
	}//end resolveDecryptedCredentials()

	/**
	 * Human-readable display name for a provider.
	 *
	 * @param string $name The provider name.
	 *
	 * @return string
	 */
	private function displayNameFor(string $name): string {
		$map = [
			'mollie' => 'Mollie',
			'ccv' => 'CCV',
			'adyen' => 'Adyen',
			'stripe' => 'Stripe',
		];

		return ($map[$name] ?? ucfirst($name));
	}//end displayNameFor()

	/**
	 * Provider type (online vs. terminal).
	 *
	 * @param string $name The provider name.
	 *
	 * @return string
	 */
	private function typeFor(string $name): string {
		$map = [
			'mollie' => 'online',
			'stripe' => 'online',
			'ccv' => 'terminal',
			'adyen' => 'terminal',
		];

		return ($map[$name] ?? 'online');
	}//end typeFor()

	// ---------------------------------------------------------------------
	// Internals — IAppConfig accessors.
	// ---------------------------------------------------------------------

	/**
	 * Read a scalar config value as string.
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 * @param string $default The default.
	 *
	 * @return string
	 */
	private function readString(string $name, string $key, string $default = ''): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$default
		);
	}//end readString()

	/**
	 * Read a config boolean.
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 * @param bool $default The default.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) generic config accessor — the default IS the value being requested, not a behavioural branch
	 */
	private function readBool(string $name, string $key, bool $default = false): bool {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			''
		);
		if ($raw === '') {
			return $default;
		}

		return ($raw === 'true' || $raw === '1');
	}//end readBool()

	/**
	 * Read a JSON-encoded object config value.
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 *
	 * @return array<string, mixed>
	 */
	private function readObject(string $name, string $key): array {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			''
		);
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end readObject()

	/**
	 * Write a string config value.
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 * @param string $value The value.
	 *
	 * @return void
	 */
	private function writeString(string $name, string $key, string $value): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$value
		);
	}//end writeString()

	/**
	 * Write a boolean config value (as the string 'true'/'false').
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 * @param bool $value The value.
	 *
	 * @return void
	 */
	private function writeBool(string $name, string $key, bool $value): void {
		$encoded = 'false';
		if ($value === true) {
			$encoded = 'true';
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$encoded
		);
	}//end writeBool()

	/**
	 * Write a JSON-encoded object config value.
	 *
	 * @param string $name The provider name.
	 * @param string $key The field name.
	 * @param array<string, mixed> $value The value.
	 *
	 * @return void
	 */
	private function writeObject(string $name, string $key, array $value): void {
		$encoded = json_encode($value);
		if (is_string($encoded) === false) {
			return;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_PREFIX . $name . '.' . $key,
			$encoded
		);
	}//end writeObject()

	// ---------------------------------------------------------------------
	// Internals — OR ObjectService.
	// ---------------------------------------------------------------------

	/**
	 * Load a posTransaction by id (throws OCSNotFoundException when missing).
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OCSNotFoundException
	 */
	private function loadTransaction(string $id): array {
		if ($id === '') {
			throw new OCSNotFoundException('Transaction not found');
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();
		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Transaction not found');
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new OCSNotFoundException('Transaction storage unavailable');
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			throw new OCSNotFoundException('Transaction not found');
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new OCSNotFoundException('Transaction not found');
		}

		return $data;
	}//end loadTransaction()

	/**
	 * Find a transaction by paymentSessionId.
	 *
	 * `register` / `schema` MUST sit inside `filters`. OpenRegister's
	 * `ObjectService::prepareFindAllConfig()` reads the query context from
	 * `$config['filters']['register']` / `['schema']` and from nowhere else,
	 * even though `findAll()`'s own docblock lists them as top-level keys. A
	 * top-level pair leaves `currentRegister` / `currentSchema` untouched and
	 * `MagicMapper::findAll()` then logs a warning and returns `[]`
	 * (pipelinq#793) — which this method reported as `null`, i.e. as the
	 * ordinary "no such transaction" outcome, so every settlement webhook was
	 * discarded (pipelinq#799).
	 *
	 * `limit: 2` exists to detect a duplicate `paymentSessionId`; the second
	 * row is now actually inspected instead of being silently dropped.
	 *
	 * @param string $sessionId The session id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findTransactionBySessionId(string $sessionId): ?array {
		if ($sessionId === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$result = $objectService->findAll(
				config: [
					'filters' => [
						'paymentSessionId' => $sessionId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 2,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS payment: transaction lookup by session id failed',
				[
					'session' => $sessionId,
					'class' => get_class($e),
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		$rows = $this->resultToArray(result: $result);
		if ($rows === []) {
			return null;
		}

		if (count($rows) > 1) {
			// Two transactions carrying one provider session id is data
			// corruption on the money path: the settlement would land on
			// whichever row the store happened to return first. Surface it.
			$this->logger->error(
				'Pipelinq POS payment: paymentSessionId is not unique; settling the first match',
				[
					'session' => $sessionId,
					'matches' => array_map(
						static function (array $row): string {
							return (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
						},
						$rows
					),
				]
			);
		}

		return $rows[0];
	}//end findTransactionBySessionId()

	/**
	 * Save a patch onto a posTransaction.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $patch The fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private function saveTransaction(string $id, array $patch): array {
		$current = $this->loadTransaction(id: $id);
		unset($current['@self']);

		foreach ($patch as $key => $value) {
			$current[$key] = $value;
		}

		$register = $this->registerId();
		$schema = $this->posTransactionSchema();

		// Fail closed on an unconfigured register/schema. Today this is
		// defence in depth, not a live hole: loadTransaction() above reads the
		// same two ids and throws OCSNotFoundException on '', so this branch is
		// currently unreachable (and is therefore not covered by a test — a
		// test asserting it would be asserting loadTransaction's behaviour).
		// It is stated locally anyway because the write MUST NOT run on an
		// empty id: an empty id is not the same as "no id" to ObjectService,
		// which skips setRegister()/setSchema() for an empty value and would
		// persist the transaction into whatever register/schema context an
		// earlier call in the same request left behind. The guard must not
		// depend on a read further up staying where it is.
		if ($register === '' || $schema === '') {
			$this->logger->warning(
				'Pipelinq POS payment: saveTransaction refused — register/posTransaction_schema not configured',
				['transactionId' => $id]
			);
			return $current;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$saved = $objectService->saveObject(
				object: $current,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS payment: saveTransaction failed',
				['transactionId' => $id]
			);
			return $current;
		}

		return ($this->toArray(object: $saved) ?? $current);
	}//end saveTransaction()

	/**
	 * Pipelinq register id (from app config).
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq POS payment: app-config "register" is not configured; transaction storage is refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * PosTransaction schema id (from app config).
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty schema must never be handed to
	 * OpenRegister — ObjectService skips setSchema() for an empty value, so the
	 * write silently lands in whatever schema context an earlier call in the
	 * same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured schema id, or '' when unconfigured.
	 */
	private function posTransactionSchema(): string {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');
		if ($schemaId === '') {
			$this->logger->warning(
				'Pipelinq POS payment: app-config "posTransaction_schema" is not configured; storage is refused, not run unscoped'
			);
		}

		return $schemaId;
	}//end posTransactionSchema()

	/**
	 * Group lookup: true when the user is in the POS managers group.
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool
	 */
	private function isManager(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$managersGroup = $this->appConfig->getValueString(
			Application::APP_ID,
			'pos_managers_group',
			'pos_managers'
		);

		if ($this->groupMgr->isAdmin($userId) === true) {
			return true;
		}

		if ($this->groupMgr->isInGroup($userId, $managersGroup) === true) {
			return true;
		}

		return false;
	}//end isManager()

	/**
	 * Normalise an OpenRegister result list into a plain array of rows.
	 *
	 * @param mixed $result The result.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function resultToArray(mixed $result): array {
		if (is_array($result) === true) {
			// Handle envelope shapes like { results: [...] } or flat lists.
			$hasResultsEnvelope = isset($result['results']) === true && is_array($result['results']) === true;
			$rows = $result;
			if ($hasResultsEnvelope === true) {
				$rows = $result['results'];
			}

			$out = [];
			foreach ($rows as $row) {
				$arr = $this->toArray(object: $row);
				if ($arr !== null) {
					$out[] = $arr;
				}
			}

			return $out;
		}

		return [];
	}//end resultToArray()

	/**
	 * Normalise an OpenRegister entity to a plain array.
	 *
	 * @param mixed $object The entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()

	// ---------------------------------------------------------------------
	// Internals — CloudEvent payload builders.
	// ---------------------------------------------------------------------

	/**
	 * Build the CloudEvent payload for a settled transaction.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSettledPayload(array $transaction): array {
		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_SETTLED,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->nowIso(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => $id,
				'reference' => (string)($transaction['reference'] ?? ''),
				'paymentProvider' => (string)($transaction['paymentProvider'] ?? ''),
				'paymentMethod' => (string)($transaction['paymentMethod'] ?? ''),
				'paymentSessionId' => (string)($transaction['paymentSessionId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'settledAt' => (string)($transaction['settledAt'] ?? $this->nowIso()),
			],
		];
	}//end buildSettledPayload()

	/**
	 * Build the CloudEvent payload for a refunded transaction.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 * @param string $refundId The refund id.
	 * @param string $reason The reason.
	 *
	 * @return array<string, mixed>
	 */
	private function buildRefundedPayload(array $transaction, string $refundId, string $reason): array {
		$id = (string)($transaction['@self']['id'] ?? ($transaction['id'] ?? ''));

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_REFUNDED,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->nowIso(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => $id,
				'reference' => (string)($transaction['reference'] ?? ''),
				'paymentProvider' => (string)($transaction['paymentProvider'] ?? ''),
				'paymentSessionId' => (string)($transaction['paymentSessionId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'refundId' => $refundId,
				'refundReason' => $reason,
				'refundedAt' => (string)($transaction['refundedAt'] ?? $this->nowIso()),
			],
		];
	}//end buildRefundedPayload()

	/**
	 * Dispatch a CloudEvent through OR's WebhookService (fire-and-forget).
	 *
	 * @param string $eventName The event name.
	 * @param array<string, mixed> $payload The CloudEvent envelope.
	 *
	 * @return void
	 */
	private function dispatch(string $eventName, array $payload): void {
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS payment: CloudEvent not dispatched (no consumer or OR unavailable)',
				['event' => $eventName]
			);
		}//end try
	}//end dispatch()

	/**
	 * Generate a v4-ish UUID string.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()
}//end class
