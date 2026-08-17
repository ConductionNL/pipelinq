<?php

/**
 * Pipelinq LogiusConnector.
 *
 * Berichtenbox-koppelvlak BBK 1.7 API wrapper. Acquires an OAuth 2.0
 * client-credentials token, signs requests with the tenant's
 * PKI-overheid certificate, posts BBK 1.7-shaped REST/JSON payloads,
 * and validates inbound Logius webhook signatures.
 *
 * The HTTP client is the standard Nextcloud IClient — that way the
 * connector cooperates with the platform's TLS, proxy and retry
 * defaults. For unit tests, a fake IClient is injected via the
 * constructor.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-bbk-010
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-002
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-receipt-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DOMDocument;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Berichtenbox-koppelvlak BBK 1.7 API client.
 */
class LogiusConnector {
	/**
	 * Maximum total attachment size (BBK 1.7).
	 *
	 * @var int
	 */
	public const MAX_ATTACHMENT_BYTES = (25 * 1024 * 1024);

	/**
	 * Allowed attachment MIME types (BBK 1.7).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_MIME = [
		'application/pdf',
		'image/png',
		'image/jpeg',
	];

	/**
	 * Maximum subject length (BBK 1.7).
	 *
	 * @var int
	 */
	public const MAX_SUBJECT_CHARS = 200;

	/**
	 * Default Logius base URL — overridable via app config.
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://api.logius.nl/berichtenbox/v1.7';

	/**
	 * App config key for the OAuth token endpoint.
	 *
	 * @var string
	 */
	public const CONFIG_TOKEN_URL = 'logius_token_url';

	/**
	 * App config key for the Berichtenbox base URL.
	 *
	 * @var string
	 */
	public const CONFIG_BASE_URL = 'logius_base_url';

	/**
	 * App config key for the OAuth client id.
	 *
	 * @var string
	 */
	public const CONFIG_CLIENT_ID = 'logius_client_id';

	/**
	 * App config key for the OAuth client secret.
	 *
	 * @var string
	 */
	public const CONFIG_CLIENT_SECRET = 'logius_client_secret';

	/**
	 * App config key for the webhook signing secret.
	 *
	 * @var string
	 */
	public const CONFIG_WEBHOOK_SECRET = 'logius_webhook_secret';

	/**
	 * Cached OAuth token tuple [bearer, expiresAtUnix].
	 *
	 * @var array{0: string, 1: int}|null
	 */
	private ?array $tokenCache = null;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IAppConfig $appConfig App config service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Obtain a Bearer token via OAuth 2.0 client-credentials.
	 *
	 * The token is cached in-process until expiry.
	 *
	 * @return string Bearer token.
	 *
	 * @throws RuntimeException If authentication fails.
	 */
	public function authenticate(): string {
		$now = time();
		if ($this->tokenCache !== null && $this->tokenCache[1] > ($now + 30)) {
			return $this->tokenCache[0];
		}

		$tokenUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_TOKEN_URL,
			self::DEFAULT_BASE_URL . '/oauth/token'
		);
		$clientId = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_CLIENT_ID, '');
		$clientSecret = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_CLIENT_SECRET, '');

		if ($clientId === '' || $clientSecret === '') {
			throw new RuntimeException('Logius client credentials are not configured.');
		}

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$tokenUrl,
				[
					'auth' => [$clientId, $clientSecret],
					'body' => ['grant_type' => 'client_credentials'],
					'timeout' => 30,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Logius OAuth token request failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Logius authentication failed: ' . $e->getMessage(), 0, $e);
		}

		$payload = $this->decodeJson(response: $response);
		if (isset($payload['access_token']) === false || is_string($payload['access_token']) === false) {
			throw new RuntimeException('Logius OAuth response missing access_token.');
		}

		$expiresIn = (int)($payload['expires_in'] ?? 3600);
		$this->tokenCache = [(string)$payload['access_token'], ($now + $expiresIn)];
		return $this->tokenCache[0];
	}//end authenticate()

	/**
	 * Validate and send a BerichtenboxMessage payload to Logius.
	 *
	 * The message argument is the OR object array form (we deliberately
	 * avoid coupling this connector to a specific entity class).
	 *
	 * @param array $message The message object (must contain
	 *                       uuid, subject, body, attachments).
	 * @param string $tenantPkiCert PEM-encoded PKI-overheid certificate.
	 * @param string $tenantPkiKey PEM-encoded private key.
	 *
	 * @return array{logiusMessageId: string, raw: array} The Logius response.
	 *
	 * @throws RuntimeException On validation or transport failure.
	 */
	public function sendMessage(array $message, string $tenantPkiCert, string $tenantPkiKey): array {
		$this->validateOutboundPayload(message: $message);

		$body = [
			'messageId' => (string)($message['uuid'] ?? $message['id'] ?? $this->newUuidV4()),
			'subject' => (string)$message['subject'],
			'body' => (string)$message['body'],
			'attachments' => array_map(
				static function (array $att): array {
					return [
						'filename' => (string)($att['filename'] ?? 'attachment'),
						'mimeType' => (string)($att['mime'] ?? 'application/pdf'),
						'sizeBytes' => (int)($att['sizeBytes'] ?? 0),
						'content' => (string)($att['contentBase64'] ?? ''),
					];
				},
				($message['attachments'] ?? [])
			),
			'recipient' => ['bsn' => (string)($message['bsnPlain'] ?? '')],
		];

		$baseUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_BASE_URL,
			self::DEFAULT_BASE_URL
		);
		$endpoint = rtrim($baseUrl, '/') . '/messages';

		$signature = $this->signRequest(body: $body, pkiCertPem: $tenantPkiCert, pkiKeyPem: $tenantPkiKey);
		$headers = [
			'Authorization' => 'Bearer ' . $this->authenticate(),
			'Content-Type' => 'application/json',
			'X-Message-ID' => $body['messageId'],
			'X-Timestamp' => gmdate('c'),
			'X-Signature-Algorithm' => 'RSA-SHA256',
			'X-Signature' => $signature,
		];

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$endpoint,
				[
					'headers' => $headers,
					'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					'timeout' => 60,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Logius sendMessage transport failure.',
				['exception' => $e->getMessage(), 'messageId' => $body['messageId']]
			);
			throw new RuntimeException('Logius sendMessage failed: ' . $e->getMessage(), 0, $e);
		}

		$raw = $this->decodeJson(response: $response);
		$logiusMessageId = (string)($raw['logiusMessageId'] ?? $raw['messageId'] ?? '');
		if ($logiusMessageId === '') {
			throw new RuntimeException('Logius response missing logiusMessageId.');
		}

		return [
			'logiusMessageId' => $logiusMessageId,
			'raw' => $raw,
		];
	}//end sendMessage()

	/**
	 * Validate that a message conforms to BBK 1.7 outbound rules.
	 *
	 * @param array $message The candidate message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On any validation failure (with reason).
	 */
	public function validateOutboundPayload(array $message): void {
		$subject = (string)($message['subject'] ?? '');
		if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
			throw new RuntimeException('subject must be 1.. ' . self::MAX_SUBJECT_CHARS . ' chars.');
		}

		$body = (string)($message['body'] ?? '');
		if ($body === '') {
			throw new RuntimeException('body must be non-empty XHTML strict.');
		}

		$this->assertWellFormedXhtml(body: $body);
		$this->validateAttachments(attachments: ($message['attachments'] ?? []));
	}//end validateOutboundPayload()

	/**
	 * Assert a message body is well-formed XHTML strict.
	 *
	 * @param string $body The message body.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the body does not parse as well-formed XML.
	 */
	private function assertWellFormedXhtml(string $body): void {
		// XHTML strict validation: must parse as well-formed XML.
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new DOMDocument();
			$wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>' . $body . '</root>';
			if ($doc->loadXML($wrapped) === false) {
				throw new RuntimeException('body is not valid XHTML strict.');
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}//end assertWellFormedXhtml()

	/**
	 * Validate the outbound attachments list (shape, mime allow-list, total size).
	 *
	 * @param mixed $attachments The candidate attachments value.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On any validation failure (with reason).
	 */
	private function validateAttachments(mixed $attachments): void {
		if (is_array($attachments) === false) {
			throw new RuntimeException('attachments must be an array.');
		}

		$totalSize = 0;
		foreach ($attachments as $att) {
			if (is_array($att) === false) {
				throw new RuntimeException('attachment entries must be arrays.');
			}

			$mime = (string)($att['mime'] ?? '');
			if (in_array($mime, self::ALLOWED_MIME, true) === false) {
				throw new RuntimeException('attachment mime must be PDF/PNG/JPG.');
			}

			$totalSize += (int)($att['sizeBytes'] ?? 0);
		}

		if ($totalSize > self::MAX_ATTACHMENT_BYTES) {
			throw new RuntimeException('attachments total size exceeds 25 MB.');
		}
	}//end validateAttachments()

	/**
	 * Sign a request body with the tenant's PKI-overheid private key.
	 *
	 * Returns a base64-encoded RSA-SHA256 signature suitable for the
	 * X-Signature header. Falls back to a placeholder when keys are
	 * empty (test only).
	 *
	 * @param array $body Canonicalised request body.
	 * @param string $pkiCertPem PEM-encoded cert (currently informational).
	 * @param string $pkiKeyPem PEM-encoded private key (RSA).
	 *
	 * @return string Base64-encoded signature.
	 *
	 * @throws RuntimeException If the key cannot be loaded or signing fails.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $pkiCertPem is part of the
	 *  public signRequest contract (kept for callers/tests and future cert-pinning);
	 *  the signature itself only needs the private key.
	 */
	public function signRequest(array $body, string $pkiCertPem, string $pkiKeyPem): string {
		if ($pkiKeyPem === '') {
			// No tenant cert configured — emit a deterministic dev signature
			// so tests can still exercise the header pipeline. Production
			// tenants MUST configure their PKI-overheid key (validated in
			// the deploy checklist).
			return base64_encode(hash('sha256', json_encode($body), true));
		}

		$key = openssl_pkey_get_private($pkiKeyPem);
		if ($key === false) {
			throw new RuntimeException('Invalid tenant PKI-overheid private key.');
		}

		$signature = '';
		$payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$signed = openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);
		if ($signed === false) {
			throw new RuntimeException('openssl_sign failed for Logius request.');
		}

		return base64_encode($signature);
	}//end signRequest()

	/**
	 * Ask Logius whether a mailbox exists for the given BSN.
	 *
	 * @param string $bsn Plaintext BSN.
	 *
	 * @return bool True iff Logius reports the burger has an active mailbox.
	 *
	 * @throws RuntimeException On transport or auth failure.
	 */
	public function checkMailboxExists(string $bsn): bool {
		$baseUrl = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_BASE_URL,
			self::DEFAULT_BASE_URL
		);
		$endpoint = rtrim($baseUrl, '/') . '/mailbox/check';

		$client = $this->clientService->newClient();
		try {
			$response = $client->post(
				$endpoint,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $this->authenticate(),
						'Content-Type' => 'application/json',
					],
					'body' => json_encode(['bsn' => $bsn]),
					'timeout' => 30,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Logius mailbox-check failed.',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Logius mailbox-check failed: ' . $e->getMessage(), 0, $e);
		}

		$payload = $this->decodeJson(response: $response);
		return (bool)($payload['available'] ?? false);
	}//end checkMailboxExists()

	/**
	 * Verify a Logius webhook signature per BBK 1.7 (HMAC-SHA256 of the
	 * raw body with the tenant-configured webhook secret, transported in
	 * the X-Logius-Signature header).
	 *
	 * @param IRequest $request The incoming Nextcloud request.
	 * @param string $rawBody The raw request body (because IRequest
	 *                        may consume the stream when parsing).
	 *
	 * @return bool True iff the signature is valid.
	 */
	public function handleWebhookSignature(IRequest $request, string $rawBody): bool {
		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CONFIG_WEBHOOK_SECRET,
			''
		);
		if ($secret === '') {
			$this->logger->warning(
				'Logius webhook secret is not configured; refusing webhook.'
			);
			return false;
		}

		$headerName = 'X-Logius-Signature';
		$provided = (string)$request->getHeader($headerName);
		if ($provided === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $provided);
	}//end handleWebhookSignature()

	/**
	 * Generate a RFC 4122 v4 UUID.
	 *
	 * @return string The UUID string.
	 */
	public function newUuidV4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuidV4()

	/**
	 * Decode an HTTP JSON response and bail out on malformed payloads.
	 *
	 * @param IResponse $response The HTTP response.
	 *
	 * @return array Decoded payload.
	 *
	 * @throws RuntimeException On invalid JSON.
	 */
	private function decodeJson(IResponse $response): array {
		$body = (string)$response->getBody();
		if ($body === '') {
			return [];
		}

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new RuntimeException(
				'Logius response is not valid JSON: ' . json_last_error_msg()
			);
		}

		return $decoded;
	}//end decodeJson()
}//end class
