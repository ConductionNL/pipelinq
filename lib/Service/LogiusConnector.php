<?php

/**
 * Pipelinq LogiusConnector.
 *
 * Wrapper around the Logius Berichtenbox-koppelvlak (BBK 1.7) REST API: OAuth
 * 2.0 client-credentials authentication, outbound message dispatch with
 * PKIoverheid request signing, mailbox-availability lookup, and inbound webhook
 * signature verification. All credentials and the PKIoverheid certificate are
 * read from the Nextcloud app-config vault (sensitive values) — never hardcoded
 * (ADR-005). This class performs no business logic and never logs raw BSNs.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-CONFORMANCE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\LogiusApiException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * BBK 1.7 API connector for the Logius Berichtenbox.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-CONFORMANCE-008
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LogiusConnector
{
    /**
     * Maximum total attachment size (25 MB) per BBK 1.7.
     *
     * @var int
     */
    public const MAX_ATTACHMENT_BYTES = (25 * 1024 * 1024);

    /**
     * Allowed attachment MIME types per BBK 1.7.
     *
     * @var array<int, string>
     */
    public const ALLOWED_MIME = ['application/pdf', 'image/png', 'image/jpeg'];

    /**
     * Maximum subject length per BBK 1.7.
     *
     * @var int
     */
    public const MAX_SUBJECT_LEN = 200;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param IAppConfig      $appConfig     The app config (vault).
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Obtain an OAuth 2.0 client-credentials Bearer token from Logius.
     *
     * @return string The Bearer access token.
     *
     * @throws LogiusApiException On auth failure or missing credentials.
     */
    public function authenticate(): string
    {
        $tokenUrl     = $this->cfg(key: 'berichtenbox_logius_token_url');
        $clientId     = $this->vault(key: 'berichtenbox_logius_client_id');
        $clientSecret = $this->vault(key: 'berichtenbox_logius_client_secret');

        if ($tokenUrl === '' || $clientId === '' || $clientSecret === '') {
            throw new LogiusApiException(message: 'Logius client credentials are not configured.', reason: 'auth');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                uri: $tokenUrl,
                options: [
                    'body'    => [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'scope'         => 'berichtenbox',
                    ],
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 15,
                ]
            );
        } catch (Throwable $e) {
            throw new LogiusApiException(message: 'Logius token request failed.', reason: 'auth');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $token   = '';
        if (is_array($decoded) === true) {
            $token = (string) ($decoded['access_token'] ?? '');
        }

        if ($token === '') {
            throw new LogiusApiException(message: 'Logius token response did not contain an access token.', reason: 'auth');
        }

        return $token;
    }//end authenticate()

    /**
     * Send an outbound message to the Berichtenbox.
     *
     * @param array<string, mixed> $message The message data (subject, body, bsn, attachments, ...).
     *
     * @return array{logiusMessageId: string, status: string} The Logius response.
     *
     * @throws LogiusApiException On validation or transport failure.
     */
    public function sendMessage(array $message): array
    {
        $this->validateOutbound(message: $message);

        $token   = $this->authenticate();
        $apiBase = $this->cfg(key: 'berichtenbox_logius_api_base');
        if ($apiBase === '') {
            throw new LogiusApiException(message: 'Logius API base URL is not configured.', reason: 'validation');
        }

        $payload = [
            'message-id'  => (string) ($message['messageId'] ?? ''),
            'bsn'         => (string) ($message['bsn'] ?? ''),
            'subject'     => (string) $message['subject'],
            'body'        => (string) $message['body'],
            'attachments' => array_values((array) ($message['attachments'] ?? [])),
        ];

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                uri: rtrim($apiBase, '/').'/berichten',
                options: [
                    'body'    => json_encode($payload),
                    'headers' => $this->signedHeaders(token: $token, body: (string) json_encode($payload)),
                    'timeout' => 30,
                ]
            );
        } catch (Throwable $e) {
            throw new LogiusApiException(message: 'Logius message dispatch failed.', reason: 'network');
        }

        $status = $response->getStatusCode();
        if ($status === 429) {
            throw new LogiusApiException(message: 'Logius rate limit exceeded.', reason: 'rate-limit');
        }

        if ($status >= 500) {
            throw new LogiusApiException(message: 'Logius server error.', reason: 'server');
        }

        if ($status >= 400) {
            throw new LogiusApiException(message: 'Logius rejected the message.', reason: 'validation');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $msgId   = '';
        if (is_array($decoded) === true) {
            $msgId = (string) ($decoded['message-id'] ?? ($decoded['messageId'] ?? ''));
        }

        return [
            'logiusMessageId' => $msgId,
            'status'          => 'sent',
        ];
    }//end sendMessage()

    /**
     * Check whether a BSN has an active MijnOverheid mailbox.
     *
     * @param string $bsn The plaintext BSN (used only for the request, never logged).
     *
     * @return bool True when a mailbox exists.
     *
     * @throws LogiusApiException On transport or auth failure.
     */
    public function checkMailboxExists(string $bsn): bool
    {
        $token   = $this->authenticate();
        $apiBase = $this->cfg(key: 'berichtenbox_logius_api_base');
        if ($apiBase === '') {
            throw new LogiusApiException(message: 'Logius API base URL is not configured.', reason: 'validation');
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                uri: rtrim($apiBase, '/').'/mailbox-check',
                options: [
                    'body'    => json_encode(['bsn' => $bsn]),
                    'headers' => $this->signedHeaders(token: $token, body: (string) json_encode(['bsn' => $bsn])),
                    'timeout' => 15,
                ]
            );
        } catch (Throwable $e) {
            throw new LogiusApiException(message: 'Logius mailbox check failed.', reason: 'network');
        }

        if ($response->getStatusCode() >= 400) {
            throw new LogiusApiException(message: 'Logius mailbox check returned an error.', reason: 'server');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded) === true) {
            return (bool) ($decoded['mailboxAvailable'] ?? false);
        }

        return false;
    }//end checkMailboxExists()

    /**
     * Verify the HMAC signature on an inbound Logius webhook.
     *
     * Per BBK 1.7, Logius signs each callback with the shared webhook secret
     * over the raw request body. The signature header is compared in constant
     * time. An unsigned or mismatched request MUST be rejected (ADR-005).
     *
     * @param string $rawBody     The raw (unparsed) request body.
     * @param string $providedSig The signature from the request header (hex).
     *
     * @return bool True when the signature is valid.
     */
    public function verifyWebhookSignature(string $rawBody, string $providedSig): bool
    {
        $secret = $this->vault(key: 'berichtenbox_logius_webhook_secret');
        if ($secret === '' || $providedSig === '') {
            $this->logger->warning('Berichtenbox: webhook rejected — missing secret or signature');
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        $valid    = hash_equals($expected, strtolower(trim($providedSig)));
        if ($valid === false) {
            $this->logger->warning('Berichtenbox: webhook signature mismatch');
        }

        return $valid;
    }//end verifyWebhookSignature()

    /**
     * Validate an outbound message against BBK 1.7 constraints.
     *
     * @param array<string, mixed> $message The message data.
     *
     * @return void
     *
     * @throws LogiusApiException When a constraint is violated.
     */
    public function validateOutbound(array $message): void
    {
        $subject = (string) ($message['subject'] ?? '');
        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT_LEN) {
            throw new LogiusApiException(message: 'Subject must be 1-200 characters.', reason: 'validation');
        }

        $body = (string) ($message['body'] ?? '');
        if ($body === '') {
            throw new LogiusApiException(message: 'Body must not be empty.', reason: 'validation');
        }

        $totalBytes = 0;
        foreach ((array) ($message['attachments'] ?? []) as $attachment) {
            $mime = (string) ($attachment['mime'] ?? '');
            if (in_array($mime, self::ALLOWED_MIME, true) === false) {
                throw new LogiusApiException(message: 'Attachment MIME type not allowed.', reason: 'validation');
            }

            $totalBytes += (int) ($attachment['sizeBytes'] ?? 0);
        }

        if ($totalBytes > self::MAX_ATTACHMENT_BYTES) {
            throw new LogiusApiException(message: 'Attachments exceed the 25 MB limit.', reason: 'validation');
        }
    }//end validateOutbound()

    /**
     * Build request headers including the PKIoverheid request signature.
     *
     * @param string $token The OAuth Bearer token.
     * @param string $body  The request body to sign.
     *
     * @return array<string, string> The headers.
     */
    private function signedHeaders(string $token, string $body): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        $pkiKey = $this->vault(key: 'berichtenbox_pki_key');
        if ($pkiKey !== '') {
            $privateKey = openssl_pkey_get_private($pkiKey);
            $signature  = '';
            if ($privateKey !== false
                && openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA256) === true
            ) {
                $headers['X-PKIoverheid-Signature'] = base64_encode($signature);
            }
        }

        return $headers;
    }//end signedHeaders()

    /**
     * Read a non-sensitive config value.
     *
     * @param string $key The config key.
     *
     * @return string The value (empty string if unset).
     */
    private function cfg(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end cfg()

    /**
     * Read a sensitive (vault) config value.
     *
     * @param string $key The config key.
     *
     * @return string The value (empty string if unset).
     */
    private function vault(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end vault()
}//end class
