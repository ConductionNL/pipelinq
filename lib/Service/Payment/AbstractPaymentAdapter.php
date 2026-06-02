<?php

/**
 * Pipelinq AbstractPaymentAdapter.
 *
 * Shared plumbing for the concrete POS payment provider adapters: credential
 * holding, HTTP JSON request execution through the injected OCP client, safe
 * error normalization (no secrets / stack traces leak to the caller), constant-
 * time HMAC comparison, and amount-to-cents conversion.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use OCP\Http\Client\IClient;
use Psr\Log\LoggerInterface;

/**
 * Base class for the concrete payment provider adapters.
 *
 * The credentials array carries the already-decrypted secrets the owning
 * PosPaymentService loaded from IAppConfig: `apiKey`, `apiSecret`,
 * `webhookSecret`, plus the non-secret `environment`, `testMode` and provider
 * `config`. Secrets are held only for the lifetime of the adapter instance and
 * are never logged.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Adapters wire the HTTP client +
 *  logger they legitimately need; no extra indirection would reduce real coupling.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
abstract class AbstractPaymentAdapter implements PaymentProviderInterface
{
    /**
     * Default outbound request timeout in seconds (REQ-PAY-010 — fail fast).
     *
     * @var int
     */
    protected const TIMEOUT = 8;

    /**
     * Constructor.
     *
     * @param IClient              $client      The OCP HTTP client.
     * @param LoggerInterface      $logger      The logger.
     * @param array<string, mixed> $credentials The decrypted credentials + config.
     */
    public function __construct(
        protected IClient $client,
        protected LoggerInterface $logger,
        protected array $credentials,
    ) {
    }//end __construct()

    /**
     * Whether the adapter is in live (production) mode.
     *
     * @return bool True when environment is 'live' and test mode is off.
     */
    protected function isLive(): bool
    {
        $environment = (string) ($this->credentials['environment'] ?? 'sandbox');
        $testMode    = (bool) ($this->credentials['testMode'] ?? true);

        return ($environment === 'live' && $testMode === false);
    }//end isLive()

    /**
     * The decrypted API key (empty string when not configured).
     *
     * @return string The API key.
     */
    protected function apiKey(): string
    {
        return (string) ($this->credentials['apiKey'] ?? '');
    }//end apiKey()

    /**
     * The decrypted API secret (empty string when not configured).
     *
     * @return string The API secret.
     */
    protected function apiSecret(): string
    {
        return (string) ($this->credentials['apiSecret'] ?? '');
    }//end apiSecret()

    /**
     * The decrypted webhook signing secret (empty string when not configured).
     *
     * @return string The webhook secret.
     */
    protected function webhookSecret(): string
    {
        return (string) ($this->credentials['webhookSecret'] ?? '');
    }//end webhookSecret()

    /**
     * A value from the provider's non-secret config block.
     *
     * @param string $key      The config key.
     * @param string $fallback The fallback when unset.
     *
     * @return string The config value.
     */
    protected function configValue(string $key, string $fallback=''): string
    {
        $config = ($this->credentials['config'] ?? []);
        if (is_array($config) === true && isset($config[$key]) === true) {
            return (string) $config[$key];
        }

        return $fallback;
    }//end configValue()

    /**
     * Convert a major-unit amount (euros) to integer minor units (cents).
     *
     * @param float $amount The amount in euros.
     *
     * @return int The amount in cents.
     */
    protected function toCents(float $amount): int
    {
        return (int) round(($amount * 100));
    }//end toCents()

    /**
     * Format a major-unit amount as a fixed 2-decimal string (Mollie/CCV style).
     *
     * @param float $amount The amount in euros.
     *
     * @return string The formatted amount, e.g. "21.53".
     */
    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }//end formatAmount()

    /**
     * Execute a JSON HTTP request and decode the response.
     *
     * Any transport failure or non-2xx response is normalized to a thrown
     * {@see PaymentApiException} carrying a user-safe message — the concrete
     * adapter catches it and returns a `status => failed` result. The provider
     * secret is never included in the exception or any log line.
     *
     * @param string               $method  The HTTP method (GET/POST/DELETE).
     * @param string               $url     The absolute request URL.
     * @param array<string, mixed> $headers The request headers.
     * @param array<string, mixed> $body    The JSON body (omitted for GET).
     * @param array<string, mixed> $form    A form-encoded body (Stripe-style);
     *                                      takes precedence over $body when non-empty.
     *
     * @return array<string, mixed> The decoded JSON response.
     *
     * @throws PaymentApiException On transport error, non-2xx status, or bad JSON.
     */
    protected function jsonRequest(string $method, string $url, array $headers, array $body=[], array $form=[]): array
    {
        $options = [
            'headers' => $headers,
            'timeout' => self::TIMEOUT,
        ];
        if (empty($form) === false) {
            $options['body'] = http_build_query($form);
        } else if ($method !== 'GET' && empty($body) === false) {
            $options['body'] = (string) json_encode($body);
        }

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: payment provider HTTP call failed',
                ['provider' => $this->getName(), 'exception' => $e->getMessage()]
            );
            throw new PaymentApiException('Verbinding met betalingsprovider verbroken. Probeer opnieuw.');
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                'Pipelinq: payment provider returned an error status',
                ['provider' => $this->getName(), 'status' => $status]
            );
            throw new PaymentApiException($this->mapHttpError(status: $status));
        }

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            throw new PaymentApiException('Ongeldig antwoord van de betalingsprovider.');
        }

        return $decoded;
    }//end jsonRequest()

    /**
     * Map an HTTP error status to a user-safe Dutch message.
     *
     * @param int $status The HTTP status code.
     *
     * @return string The user-facing message.
     */
    protected function mapHttpError(int $status): string
    {
        if ($status === 401 || $status === 403) {
            return 'Ongeldige API-gegevens. Controleer de sleutel van de provider.';
        }

        if ($status === 422 || $status === 400) {
            return 'De betalingsprovider weigerde het verzoek (controleer bedrag en methode).';
        }

        return 'De betalingsprovider gaf een fout terug. Probeer het later opnieuw.';
    }//end mapHttpError()

    /**
     * Constant-time comparison of two signatures.
     *
     * @param string $expected The locally computed signature.
     * @param string $provided The signature presented by the caller.
     *
     * @return bool True when they match (and neither is empty).
     */
    protected function signatureEquals(string $expected, string $provided): bool
    {
        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }//end signatureEquals()

    /**
     * Normalize an arbitrary provider status string to the paymentStatus vocab.
     *
     * @param string $providerStatus The raw provider status.
     *
     * @return string One of pending|captured|settled|failed|refunded.
     */
    abstract protected function mapStatus(string $providerStatus): string;
}//end class
