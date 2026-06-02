<?php

/**
 * Pipelinq PosPaymentService.
 *
 * Orchestrates the pluggable POS payment-provider layer: loads provider
 * configuration (from the OR paymentProvider schema) and decrypted credentials
 * (from IAppConfig, sensitive-at-rest), instantiates the matching adapter,
 * drives initiate / capture / refund, and routes signed settlement webhooks to
 * the server-authoritative transaction-status update + Shillinq CloudEvent.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS payment orchestration and webhook settlement.
 *
 * Security model (ADR-005):
 *   - Provider secrets (apiKey/apiSecret/webhookSecret) live ONLY in IAppConfig,
 *     stored with the sensitive flag; they are decrypted in-memory just-in-time
 *     to build an adapter and never returned to the client or logged.
 *   - The public webhook endpoint authenticates by provider signature: the
 *     adapter validates the raw body against the configured webhook secret;
 *     an invalid signature aborts with a 401-mapping exception before any
 *     transaction is touched. Webhook bodies are not trusted for payment state
 *     (thin-notification providers re-fetch authoritative status).
 *   - Webhook handling is idempotent: the last processed provider event id is
 *     stored on the transaction; a re-delivered event is a no-op.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  payment orchestration service legitimately needs (OR container, app config,
 *  HTTP client factory, access policy, logger).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregates the whole payment
 *  concern (provider factory + initiate/capture/refund + webhook validate/parse/
 *  settle/idempotency + event emit + OR persistence) as small cohesive methods.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public surface = the payment
 *  operations + webhook handler + provider config CRUD, each single-purpose.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Same cohesion rationale.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Breadth of one cohesive concern.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class PosPaymentService
{
    /**
     * CloudEvent type emitted when a payment settles.
     *
     * @var string
     */
    public const EVENT_SETTLED = 'pipelinq.PosPayment.settled';

    /**
     * CloudEvent type emitted when a payment is refunded.
     *
     * @var string
     */
    public const EVENT_REFUNDED = 'pipelinq.PosPayment.refunded';

    /**
     * CloudEvents source identifier for this app's payment surface.
     *
     * @var string
     */
    private const EVENT_SOURCE = '/apps/pipelinq/pos/payment';

    /**
     * Secret credential keys (stored sensitive in IAppConfig, never returned).
     *
     * @var array<int, string>
     */
    private const SECRET_KEYS = ['apiKey', 'apiSecret', 'webhookSecret'];

    /**
     * The provider names this app ships an adapter for.
     *
     * @var array<int, string>
     */
    private const KNOWN_PROVIDERS = ['mollie', 'ccv', 'adyen', 'stripe'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container     The DI container.
     * @param IAppConfig         $appConfig     The app config.
     * @param IClientService     $clientService The HTTP client factory.
     * @param PosAccessPolicy    $policy        The shared POS access policy.
     * @param LoggerInterface    $logger        The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IClientService $clientService,
        private PosAccessPolicy $policy,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Load and instantiate the adapter for an active provider.
     *
     * Loads the provider's non-secret config from the paymentProvider schema,
     * decrypts its secrets from IAppConfig, and builds the matching adapter with
     * a fresh HTTP client. Throws if the provider is unknown, not configured, or
     * inactive (fail closed).
     *
     * @param string $name The provider name (mollie/ccv/adyen/stripe).
     *
     * @return PaymentProviderInterface The instantiated adapter.
     *
     * @throws OCSBadRequestException If the provider is unknown / inactive.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function getPaymentProvider(string $name): PaymentProviderInterface
    {
        $config = $this->loadProviderConfig(name: $name);
        if ($config === null || (bool) ($config['isActive'] ?? false) === false) {
            throw new OCSBadRequestException("Provider {$name} is niet geconfigureerd of uitgeschakeld.");
        }

        return $this->buildAdapter(name: $name, config: $config);
    }//end getPaymentProvider()

    /**
     * Build an adapter from a config array, injecting decrypted secrets.
     *
     * Used internally by getPaymentProvider() and by the webhook / test paths
     * (which load the config themselves). Never returns the secrets to a caller.
     *
     * @param string               $name   The provider name.
     * @param array<string, mixed> $config The provider config (non-secret).
     *
     * @return PaymentProviderInterface The adapter.
     *
     * @throws OCSBadRequestException If the provider name is unknown.
     */
    private function buildAdapter(string $name, array $config): PaymentProviderInterface
    {
        $credentials = [
            'environment' => (string) ($config['environment'] ?? 'sandbox'),
            'testMode'    => (bool) ($config['testMode'] ?? true),
            'config'      => ($config['config'] ?? []),
        ];
        foreach (self::SECRET_KEYS as $secretKey) {
            $credentials[$secretKey] = $this->readSecret(provider: $name, key: $secretKey);
        }

        $client = $this->clientService->newClient();
        switch ($name) {
            case 'mollie':
                return new MollieAdapter(client: $client, logger: $this->logger, credentials: $credentials);
            case 'ccv':
                return new CcvAdapter(client: $client, logger: $this->logger, credentials: $credentials);
            case 'adyen':
                return new AdyenAdapter(client: $client, logger: $this->logger, credentials: $credentials);
            case 'stripe':
                return new StripeAdapter(client: $client, logger: $this->logger, credentials: $credentials);
            default:
                throw new OCSBadRequestException("Onbekende betaalprovider: {$name}.");
        }
    }//end buildAdapter()

    /**
     * Initiate a payment for a transaction.
     *
     * Validates the transaction is confirmed (server-authoritative; cashiers may
     * not pay an unconfirmed cart), loads the provider, calls initiate(), and —
     * on success — persists paymentProvider / paymentSessionId / paymentMethod
     * and paymentStatus=pending. On provider failure the transaction is left in
     * confirmed state and the error is surfaced (REQ-PAY-010).
     *
     * @param string $transactionId The transaction UUID.
     * @param string $providerName  The provider name.
     * @param string $paymentMethod The normalized payment method.
     * @param string $userId        The acting user UID.
     *
     * @return array<string, mixed> The initiation result (sessionId, redirectUrl, status, error).
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSForbiddenException  If the user may not access the transaction.
     * @throws OCSBadRequestException If the transaction is not confirmed or the provider is invalid.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    public function initiatePayment(
        string $transactionId,
        string $providerName,
        string $paymentMethod,
        string $userId
    ): array {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $this->assertAccess(transaction: $transaction, userId: $userId);

        if ((string) ($transaction['status'] ?? '') !== 'confirmed') {
            throw new OCSBadRequestException('De transactie is niet bevestigd; kan geen betaling starten.');
        }

        $provider = $this->getPaymentProvider(name: $providerName);
        $amount   = (float) ($transaction['total'] ?? 0);
        $result   = $provider->initiate(transactionData: $transaction, amount: $amount, paymentMethod: $paymentMethod);

        if ((string) $result['status'] === 'failed') {
            return $result;
        }

        $transaction['paymentProvider']  = $providerName;
        $transaction['paymentSessionId'] = (string) $result['sessionId'];
        $transaction['paymentMethod']    = $paymentMethod;
        $transaction['paymentStatus']    = 'pending';
        $this->saveTransaction(id: $transactionId, transaction: $transaction);

        $this->logger->info('Pipelinq: POS payment initiated', ['id' => $transactionId, 'provider' => $providerName]);

        return $result;
    }//end initiatePayment()

    /**
     * Capture a previously initiated payment.
     *
     * @param string $transactionId The transaction UUID.
     * @param string $userId        The acting user UID.
     *
     * @return array<string, mixed> The capture result.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSForbiddenException  If the user may not access the transaction.
     * @throws OCSBadRequestException If no provider/session is recorded or the provider is invalid.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    public function capturePayment(string $transactionId, string $userId): array
    {
        $transaction = $this->fetchTransaction(id: $transactionId);
        $this->assertAccess(transaction: $transaction, userId: $userId);

        $providerName = (string) ($transaction['paymentProvider'] ?? '');
        $sessionId    = (string) ($transaction['paymentSessionId'] ?? '');
        if ($providerName === '' || $sessionId === '') {
            throw new OCSBadRequestException('Er is geen betaling gestart voor deze transactie.');
        }

        $provider = $this->getPaymentProvider(name: $providerName);
        $result   = $provider->capture(sessionId: $sessionId);

        if ((string) $result['status'] === 'captured') {
            $transaction['paymentStatus'] = 'captured';
            $this->saveTransaction(id: $transactionId, transaction: $transaction);
        }

        return $result;
    }//end capturePayment()

    /**
     * Refund a settled payment (manager only).
     *
     * @param string $transactionId The transaction UUID.
     * @param string $reason        The refund reason (required).
     * @param string $userId        The acting user UID.
     *
     * @return array<string, mixed> The refund result.
     *
     * @throws OCSNotFoundException   If the transaction does not exist.
     * @throws OCSForbiddenException  If the user is not a POS manager.
     * @throws OCSBadRequestException If the payment is not settled or the reason is empty.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    public function refundPayment(string $transactionId, string $reason, string $userId): array
    {
        if (trim($reason) === '') {
            throw new OCSBadRequestException('Vul een reden in voor de terugboeking.');
        }

        if ($this->policy->isManager(userId: $userId) === false) {
            throw new OCSForbiddenException('Alleen een beheerder mag een betaling terugboeken.');
        }

        $transaction   = $this->fetchTransaction(id: $transactionId);
        $paymentStatus = (string) ($transaction['paymentStatus'] ?? '');
        if ($paymentStatus !== 'settled' && $paymentStatus !== 'captured') {
            throw new OCSBadRequestException('De betaling is niet afgerond; terugboeken is niet mogelijk.');
        }

        $providerName = (string) ($transaction['paymentProvider'] ?? '');
        $sessionId    = (string) ($transaction['paymentSessionId'] ?? '');
        $provider     = $this->getPaymentProvider(name: $providerName);
        $result       = $provider->refund(sessionId: $sessionId, reason: $reason);

        if ((string) $result['status'] !== 'refunded') {
            return $result;
        }

        $transaction['paymentStatus'] = 'refunded';
        $transaction['refundReason']  = $reason;
        $transaction['refundedAt']    = $this->now();
        $saved = $this->saveTransaction(id: $transactionId, transaction: $transaction);

        $this->emitPaymentEvent(
            type: self::EVENT_REFUNDED,
            transaction: $saved,
            extra: ['refundId' => (string) $result['refundId'], 'refundReason' => $reason]
        );

        return $result;
    }//end refundPayment()

    /**
     * Handle an inbound provider webhook.
     *
     * Authentication boundary for the public webhook endpoint: validates the
     * provider signature over the RAW body, then parses (re-fetching
     * authoritative status where applicable) and routes to settlement. An
     * invalid signature aborts with a 401-mapping exception WITHOUT touching any
     * transaction. An unmatched session is logged and ignored (HTTP 200).
     *
     * @param string $providerName The provider name from the route.
     * @param string $rawBody      The exact raw request body bytes.
     * @param string $signature    The signature presented in the provider header.
     *
     * @return array<string, mixed> A status descriptor: `authenticated` (false when
     *                              the provider is unknown / unconfigured or the
     *                              signature does not verify — the controller then
     *                              returns 401 without mutating state), plus the
     *                              settlement outcome when authenticated.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function handleWebhook(string $providerName, string $rawBody, string $signature): array
    {
        if (in_array($providerName, self::KNOWN_PROVIDERS, true) === false) {
            return ['authenticated' => false, 'status' => 'invalid'];
        }

        $config = $this->loadProviderConfig(name: $providerName);
        if ($config === null) {
            return ['authenticated' => false, 'status' => 'invalid'];
        }

        $adapter = $this->buildAdapter(name: $providerName, config: $config);
        if ($adapter->validateWebhook(rawBody: $rawBody, signature: $signature) === false) {
            $this->logger->warning('Pipelinq: rejected payment webhook (invalid signature)', ['provider' => $providerName]);
            return ['authenticated' => false, 'status' => 'invalid'];
        }

        $parsed = $adapter->parseWebhook(payload: $this->decodeWebhookBody(rawBody: $rawBody));

        $result = $this->handleSettlement(
            providerName: $providerName,
            sessionId: (string) $parsed['sessionId'],
            status: (string) $parsed['status'],
            eventId: (string) $parsed['eventId']
        );
        $result['authenticated'] = true;

        return $result;
    }//end handleWebhook()

    /**
     * Decode a raw webhook body to an array.
     *
     * Most providers (Stripe, Adyen, CCV) POST JSON; Mollie POSTs a
     * form-encoded `id=tr_...`. Try JSON first, then fall back to form parsing
     * so every provider's body shape resolves to an array the adapter can read.
     *
     * @param string $rawBody The raw request body.
     *
     * @return array<string, mixed> The decoded body.
     */
    private function decodeWebhookBody(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        $form = [];
        parse_str($rawBody, $form);

        return $form;
    }//end decodeWebhookBody()

    /**
     * Apply a parsed settlement to the matching transaction.
     *
     * Finds the transaction by paymentSessionId, applies idempotency (a repeated
     * provider event id is a no-op), updates paymentStatus, and — on a settled /
     * refunded transition — emits the Shillinq CloudEvent. An unmatched session
     * is logged and ignored.
     *
     * @param string $providerName The provider name.
     * @param string $sessionId    The provider session reference.
     * @param string $status       The normalized payment status.
     * @param string $eventId      The provider event id (idempotency key).
     *
     * @return array<string, mixed> A status descriptor.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    public function handleSettlement(string $providerName, string $sessionId, string $status, string $eventId): array
    {
        if ($sessionId === '') {
            return ['status' => 'ignored', 'reason' => 'no-session'];
        }

        $transaction = $this->findTransactionBySession(sessionId: $sessionId);
        if ($transaction === null) {
            $this->logger->info('Pipelinq: payment webhook for unknown session', ['provider' => $providerName, 'session' => $sessionId]);
            return ['status' => 'ignored', 'reason' => 'unmatched'];
        }

        // Idempotency: a re-delivered event id is a no-op (REQ-PAY-006).
        if ($eventId !== '' && (string) ($transaction['paymentEventId'] ?? '') === $eventId) {
            return ['status' => 'duplicate', 'paymentStatus' => (string) ($transaction['paymentStatus'] ?? '')];
        }

        $id = (string) ($transaction['id'] ?? $transaction['uuid'] ?? '');
        $transaction['paymentStatus']  = $status;
        $transaction['paymentEventId'] = $eventId;
        if ($status === 'settled') {
            $transaction['settledAt'] = $this->now();
        }

        if ($status === 'refunded') {
            $transaction['refundedAt'] = $this->now();
        }

        $saved = $this->saveTransaction(id: $id, transaction: $transaction);

        if ($status === 'settled') {
            $this->emitPaymentEvent(type: self::EVENT_SETTLED, transaction: $saved, extra: []);
        }

        if ($status === 'refunded') {
            $this->emitPaymentEvent(
                type: self::EVENT_REFUNDED,
                transaction: $saved,
                extra: ['refundReason' => (string) ($saved['refundReason'] ?? '')]
            );
        }

        return ['status' => 'processed', 'paymentStatus' => $status];
    }//end handleSettlement()

    /**
     * Test a provider's connectivity / credentials and store the result.
     *
     * @param string $providerName The provider name.
     *
     * @return array{status: string, message: string} The test result.
     *
     * @throws OCSBadRequestException If the provider is unknown / unconfigured.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function testConnection(string $providerName): array
    {
        $config = $this->loadProviderConfig(name: $providerName);
        if ($config === null) {
            throw new OCSBadRequestException("Provider {$providerName} is niet geconfigureerd.");
        }

        $adapter = $this->buildAdapter(name: $providerName, config: $config);
        $result  = $adapter->testConnection();

        $config['lastTestedAt'] = $this->now();
        $config['testResult']   = $result;
        $this->saveProviderConfig(name: $providerName, config: $config);

        return $result;
    }//end testConnection()

    /**
     * List all configured providers with their public (masked) config.
     *
     * Secrets are NEVER included; each provider carries a credentialsConfigured
     * boolean derived from whether an apiKey is stored.
     *
     * @return array<int, array<string, mixed>> The masked provider configs.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    public function listProviders(): array
    {
        $providers = [];
        foreach (self::KNOWN_PROVIDERS as $name) {
            $config = $this->loadProviderConfig(name: $name);
            if ($config === null) {
                continue;
            }

            $providers[] = $this->maskConfig(name: $name, config: $config);
        }

        return $providers;
    }//end listProviders()

    /**
     * Update a provider's configuration and credentials (admin only).
     *
     * Non-secret fields are stored on the paymentProvider object; secret fields
     * (apiKey/apiSecret/webhookSecret), when present and non-empty in the input,
     * are stored sensitive-at-rest in IAppConfig. An empty/absent secret leaves
     * the stored secret unchanged (so re-saving the form does not wipe a key the
     * UI masks). Returns the masked config.
     *
     * @param string               $providerName The provider name.
     * @param array<string, mixed> $input        The submitted config + optional secrets.
     *
     * @return array<string, mixed> The masked updated config.
     *
     * @throws OCSBadRequestException If the provider name is unknown.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
     */
    public function updateProvider(string $providerName, array $input): array
    {
        if (in_array($providerName, self::KNOWN_PROVIDERS, true) === false) {
            throw new OCSBadRequestException("Onbekende betaalprovider: {$providerName}.");
        }

        $config = $this->loadProviderConfig(name: $providerName);
        if ($config === null) {
            throw new OCSNotFoundException("Provider {$providerName} is niet geconfigureerd.");
        }

        foreach (['isActive', 'environment', 'testMode', 'config', 'supportedMethods'] as $field) {
            if (array_key_exists($field, $input) === true) {
                $config[$field] = $input[$field];
            }
        }

        foreach (self::SECRET_KEYS as $secretKey) {
            $value = (string) ($input[$secretKey] ?? '');
            if ($value !== '' && $value !== '***') {
                $this->writeSecret(provider: $providerName, key: $secretKey, value: $value);
            }
        }

        $config['credentialsConfigured'] = ($this->readSecret(provider: $providerName, key: 'apiKey') !== ''
            || $this->readSecret(provider: $providerName, key: 'apiSecret') !== '');
        $this->saveProviderConfig(name: $providerName, config: $config);

        return $this->maskConfig(name: $providerName, config: $config);
    }//end updateProvider()

    /**
     * Strip secrets from a provider config for client exposure.
     *
     * @param string               $name   The provider name.
     * @param array<string, mixed> $config The provider config.
     *
     * @return array<string, mixed> The masked config.
     */
    private function maskConfig(string $name, array $config): array
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            unset($config[$secretKey]);
        }

        $config['name'] = $name;
        $config['credentialsConfigured'] = (bool) ($config['credentialsConfigured'] ?? false);

        return $config;
    }//end maskConfig()

    /**
     * Read a decrypted secret from IAppConfig (never logged / returned to client).
     *
     * @param string $provider The provider name.
     * @param string $key      The secret key (apiKey/apiSecret/webhookSecret).
     *
     * @return string The decrypted secret, or empty string when unset.
     */
    private function readSecret(string $provider, string $key): string
    {
        return $this->appConfig->getValueString(
            Application::APP_ID,
            $this->secretConfigKey(provider: $provider, key: $key),
            ''
        );
    }//end readSecret()

    /**
     * Store a secret in IAppConfig with the sensitive (encrypted-at-rest) flag.
     *
     * @param string $provider The provider name.
     * @param string $key      The secret key.
     * @param string $value    The plaintext secret.
     *
     * @return void
     */
    private function writeSecret(string $provider, string $key, string $value): void
    {
        $this->appConfig->setValueString(
            Application::APP_ID,
            $this->secretConfigKey(provider: $provider, key: $key),
            $value,
            false,
            true
        );
    }//end writeSecret()

    /**
     * The IAppConfig key for a provider secret.
     *
     * @param string $provider The provider name.
     * @param string $key      The secret key.
     *
     * @return string The config key.
     */
    private function secretConfigKey(string $provider, string $key): string
    {
        return 'payment_provider_'.$provider.'_'.$key;
    }//end secretConfigKey()

    /**
     * Assert the acting user may operate on a transaction (closes the IDOR).
     *
     * @param array<string, mixed> $transaction The transaction payload.
     * @param string               $userId      The acting user UID.
     *
     * @return void
     *
     * @throws OCSForbiddenException If the user may not access the transaction.
     */
    private function assertAccess(array $transaction, string $userId): void
    {
        if ($this->policy->canAccessTransaction(object: $transaction, userId: $userId) === false) {
            throw new OCSForbiddenException('Geen toegang tot deze transactie.');
        }
    }//end assertAccess()

    /**
     * Emit a payment CloudEvent (fire-and-forget) through OR's WebhookService.
     *
     * A missing / failed downstream subscriber must never fail the payment
     * operation (settlement is server-authoritative; the event is advisory).
     *
     * @param string               $type        The CloudEvent type.
     * @param array<string, mixed> $transaction The transaction payload.
     * @param array<string, mixed> $extra       Extra data fields (refundId, refundReason).
     *
     * @return string The generated CloudEvents id, or empty string on failure.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
     */
    public function emitPaymentEvent(string $type, array $transaction, array $extra): string
    {
        $eventId = $this->uuid();
        $data    = array_merge(
            [
                'transactionId'    => (string) ($transaction['id'] ?? $transaction['uuid'] ?? ''),
                'reference'        => (string) ($transaction['reference'] ?? ''),
                'paymentProvider'  => (string) ($transaction['paymentProvider'] ?? ''),
                'paymentMethod'    => (string) ($transaction['paymentMethod'] ?? ''),
                'paymentSessionId' => (string) ($transaction['paymentSessionId'] ?? ''),
                'total'            => (float) ($transaction['total'] ?? 0),
                'settledAt'        => (string) ($transaction['settledAt'] ?? ''),
            ],
            $extra
        );

        $payload = [
            'specversion'     => '1.0',
            'type'            => $type,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => $this->now(),
            'datacontenttype' => 'application/json',
            'data'            => $data,
        ];

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(_event: $event, eventName: $type, payload: $payload);
            return $eventId;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: POS payment CloudEvent not dispatched (no consumer or OR unavailable)',
                ['exception' => $e->getMessage(), 'type' => $type]
            );
            return '';
        }//end try
    }//end emitPaymentEvent()

    /**
     * Load a provider's configuration object from the paymentProvider schema.
     *
     * @param string $name The provider name.
     *
     * @return array<string, mixed>|null The provider config, or null when absent.
     */
    private function loadProviderConfig(string $name): ?array
    {
        [$register, $schema] = $this->config(schemaKey: 'paymentProvider_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                        'name'     => $name,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to load payment provider config', ['exception' => $e->getMessage()]);
            return null;
        }

        foreach (($results ?? []) as $result) {
            $config = $this->toArray(object: $result);
            if ((string) ($config['name'] ?? '') === $name) {
                return $config;
            }
        }

        return null;
    }//end loadProviderConfig()

    /**
     * Persist a provider configuration object.
     *
     * @param string               $name   The provider name.
     * @param array<string, mixed> $config The provider config.
     *
     * @return void
     */
    private function saveProviderConfig(string $name, array $config): void
    {
        [$register, $schema] = $this->config(schemaKey: 'paymentProvider_schema');

        // Never persist secrets onto the object (ADR-005); they live in IAppConfig.
        foreach (self::SECRET_KEYS as $secretKey) {
            unset($config[$secretKey]);
        }

        // Keep the provider name authoritative on the persisted object.
        $config['name'] = $name;

        $id = (string) ($config['id'] ?? $config['uuid'] ?? '');
        unset($config['@self']);

        $this->getObjectService()->saveObject(
            object: $config,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );
    }//end saveProviderConfig()

    /**
     * Find a transaction by its payment session id within this app's register.
     *
     * @param string $sessionId The provider session reference.
     *
     * @return array<string, mixed>|null The transaction, or null when none matches.
     */
    private function findTransactionBySession(string $sessionId): ?array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register'         => $register,
                        'schema'           => $schema,
                        'paymentSessionId' => $sessionId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to query transaction by session', ['exception' => $e->getMessage()]);
            return null;
        }

        foreach (($results ?? []) as $result) {
            $transaction = $this->toArray(object: $result);
            if ((string) ($transaction['paymentSessionId'] ?? '') === $sessionId) {
                return $transaction;
            }
        }

        return null;
    }//end findTransactionBySession()

    /**
     * Fetch a transaction from this app's register, as an array.
     *
     * @param string $id The transaction UUID.
     *
     * @return array<string, mixed> The transaction data.
     *
     * @throws OCSNotFoundException If the object is not found in this app's posTransaction schema.
     */
    private function fetchTransaction(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Transactie niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchTransaction()

    /**
     * Persist a transaction object via the OR ObjectService.
     *
     * @param string               $id          The transaction UUID.
     * @param array<string, mixed> $transaction The transaction data.
     *
     * @return array<string, mixed> The saved transaction as an array.
     */
    private function saveTransaction(string $id, array $transaction): array
    {
        [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

        unset($transaction['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $transaction,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveTransaction()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The current timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * Generate a v4 UUID.
     *
     * @return string The UUID.
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()
}//end class
