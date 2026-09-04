<?php

/**
 * Pipelinq ConnectorSourceTransport.
 *
 * Sends a rendered mail through an OpenConnector source — the path every
 * bulk-provider `mailTransport` (Amazon SES, Brevo, Mailjet, SendGrid,
 * Mailgun, Postmark) uses. Generalises the request body over
 * `PROVIDER_BODY_BUILDERS` instead of one hardcoded shape; Pipelinq never
 * imports a provider SDK or reads a provider credential (ADR-064/067/091) —
 * `CallService::call()` does the HTTP call against the source OpenConnector
 * resolves, credentials included.
 *
 * The `sendgrid` builder reproduces the exact request body Pipelinq sent
 * before this change (`to`, `subject`, `bodyHtml`, `bodyText`, `senderName`,
 * `senderEmail`, `replyTo`) so existing SendGrid sends are byte-for-byte
 * unchanged. The five other builders shape a body closer to each provider's
 * own bulk-send API, since there is no pre-existing behaviour to preserve
 * for them.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-provider-transport-never-carries-a-credential
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ConnectorSourceTransport: sends through an OpenConnector source.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-provider-transport-never-carries-a-credential
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Six provider request-body
 *  builders (SES, Brevo, Mailjet, SendGrid, Mailgun, Postmark), each a short,
 *  independently simple mapping; splitting would only scatter one cohesive
 *  "shape a request for this provider" concern across several files.
 */
final class ConnectorSourceTransport implements TransportInterface {
	/**
	 * OpenConnector's own OpenRegister register slug (Source objects live
	 * here — the register slug is frozen even where the app id has moved).
	 */
	private const OPENCONNECTOR_REGISTER_SLUG = 'openconnector';

	/**
	 * OpenConnector's Source schema slug within {@see OPENCONNECTOR_REGISTER_SLUG}.
	 */
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	/**
	 * Recognised provider names. Anything else (including the legacy
	 * unset/empty case) falls back to the `sendgrid` shape, preserving
	 * pre-existing behaviour for a `mailTransport` created before the
	 * `provider` field existed.
	 *
	 * @var string[]
	 */
	private const KNOWN_PROVIDERS = ['ses', 'brevo', 'mailjet', 'sendgrid', 'mailgun', 'postmark'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister/OpenConnector resolution).
	 * @param LoggerInterface $logger The logger.
	 * @param string $connectorSourceId OpenConnector source UUID or slug.
	 * @param string $provider The bulk provider name (one of {@see KNOWN_PROVIDERS}), or empty for legacy transports.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $connectorSourceId,
		private readonly string $provider,
	) {
	}//end __construct()

	/**
	 * Send through the resolved OpenConnector source.
	 *
	 * @param RenderedMail $mail The rendered mail to send.
	 *
	 * @return SendResult The outcome.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-provider-transport-never-carries-a-credential
	 */
	public function send(RenderedMail $mail): SendResult {
		if ($this->connectorSourceId === '') {
			$this->logger->warning('ConnectorSourceTransport.send: no connectorSourceId', ['deliveryId' => $mail->deliveryId]);
			return new SendResult(accepted: false, error: 'no-connector-source');
		}

		$source = $this->resolveSource();
		if ($source === null) {
			return new SendResult(accepted: false, error: 'connector-source-not-found');
		}

		$callService = $this->resolveCallService();
		if ($callService === null) {
			return new SendResult(accepted: false, error: 'call-service-unavailable');
		}

		$body = $this->buildRequestBody(mail: $mail);

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: '',
				method: 'POST',
				config: ['json' => $body],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.send: connector call failed',
				['connectorSourceId' => $this->connectorSourceId, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'connector-call-failed');
		}

		$callLogData = $this->toArray(value: $callLog);
		$statusCode = (int)($callLogData['statusCode'] ?? 0);
		if ($statusCode < 200 || $statusCode >= 300) {
			$this->logger->warning(
				'ConnectorSourceTransport.send: connector source responded with a non-2xx status',
				['connectorSourceId' => $this->connectorSourceId, 'statusCode' => $statusCode]
			);
			return new SendResult(accepted: false, error: 'non-2xx-response');
		}

		$providerId = $this->extractProviderId(
			result: $this->decodeCallLogResponseBody(callLogData: $callLogData),
		);
		return new SendResult(accepted: true, providerId: $providerId);
	}//end send()

	/**
	 * Resolve the OpenConnector Source object addressed by `connectorSourceId`.
	 *
	 * @return array<string, mixed>|object|null The resolved Source entity, or
	 *                                           null when unavailable/not found.
	 */
	private function resolveSource(): array|object|null {
		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$source = $objectService->find(
				id: $this->connectorSourceId,
				register: self::OPENCONNECTOR_REGISTER_SLUG,
				schema: self::OPENCONNECTOR_SOURCE_SCHEMA_SLUG,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveSource: lookup failed',
				['connectorSourceId' => $this->connectorSourceId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($source === null || (is_array($source) === false && is_object($source) === false)) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveSource: connector source not found',
				['connectorSourceId' => $this->connectorSourceId]
			);
			return null;
		}

		return $source;
	}//end resolveSource()

	/**
	 * Build the outbound JSON body for the configured provider.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed> The request body.
	 */
	private function buildRequestBody(RenderedMail $mail): array {
		$provider = $this->provider;
		if (in_array($provider, self::KNOWN_PROVIDERS, true) === false) {
			$provider = 'sendgrid';
		}

		return match ($provider) {
			'ses' => $this->buildSesBody(mail: $mail),
			'brevo' => $this->buildBrevoBody(mail: $mail),
			'mailjet' => $this->buildMailjetBody(mail: $mail),
			'mailgun' => $this->buildMailgunBody(mail: $mail),
			'postmark' => $this->buildPostmarkBody(mail: $mail),
			default => $this->buildSendGridBody(mail: $mail),
		};
	}//end buildRequestBody()

	/**
	 * The pre-existing generic body shape, unchanged, kept for `sendgrid`
	 * and any transport whose `provider` is unset (legacy compatibility).
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSendGridBody(RenderedMail $mail): array {
		return [
			'to' => $mail->toEmail,
			'subject' => $mail->subject,
			'bodyHtml' => $mail->html,
			'bodyText' => $mail->text,
			'senderName' => $mail->fromName,
			'senderEmail' => $mail->fromEmail,
			'replyTo' => $mail->replyTo,
		];
	}//end buildSendGridBody()

	/**
	 * Amazon SES v2 `SendEmail`-shaped body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSesBody(RenderedMail $mail): array {
		return [
			'FromEmailAddress' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'Destination' => ['ToAddresses' => [$mail->toEmail]],
			'ReplyToAddresses' => $this->nonEmptyList(value: $mail->replyTo),
			'Content' => [
				'Simple' => [
					'Subject' => ['Data' => $mail->subject],
					'Body' => [
						'Html' => ['Data' => $mail->html],
						'Text' => ['Data' => $mail->text],
					],
				],
			],
		];
	}//end buildSesBody()

	/**
	 * Brevo (Sendinblue) transactional-email-API-shaped body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildBrevoBody(RenderedMail $mail): array {
		$body = [
			'sender' => ['name' => $mail->fromName, 'email' => $mail->fromEmail],
			'to' => [['email' => $mail->toEmail]],
			'subject' => $mail->subject,
			'htmlContent' => $mail->html,
			'textContent' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['replyTo'] = ['email' => $mail->replyTo];
		}

		return $body;
	}//end buildBrevoBody()

	/**
	 * Mailjet Send API v3.1-shaped body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildMailjetBody(RenderedMail $mail): array {
		$message = [
			'From' => ['Email' => $mail->fromEmail, 'Name' => $mail->fromName],
			'To' => [['Email' => $mail->toEmail]],
			'Subject' => $mail->subject,
			'HTMLPart' => $mail->html,
			'TextPart' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$message['ReplyTo'] = ['Email' => $mail->replyTo];
		}

		return ['Messages' => [$message]];
	}//end buildMailjetBody()

	/**
	 * Mailgun `messages` endpoint-shaped (form-style) body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildMailgunBody(RenderedMail $mail): array {
		$body = [
			'from' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'to' => $mail->toEmail,
			'subject' => $mail->subject,
			'html' => $mail->html,
			'text' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['h:Reply-To'] = $mail->replyTo;
		}

		return $body;
	}//end buildMailgunBody()

	/**
	 * Postmark `email` endpoint-shaped body.
	 *
	 * @param RenderedMail $mail The rendered mail.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPostmarkBody(RenderedMail $mail): array {
		$body = [
			'From' => $this->formatAddress(email: $mail->fromEmail, name: $mail->fromName),
			'To' => $mail->toEmail,
			'Subject' => $mail->subject,
			'HtmlBody' => $mail->html,
			'TextBody' => $mail->text,
		];
		if ($mail->replyTo !== '') {
			$body['ReplyTo'] = $mail->replyTo;
		}

		return $body;
	}//end buildPostmarkBody()

	/**
	 * Format an RFC 5322 `"Name" <email>` address, or bare email when no name.
	 *
	 * @param string $email The address.
	 * @param string $name The display name (may be empty).
	 *
	 * @return string
	 */
	private function formatAddress(string $email, string $name): string {
		if ($name === '') {
			return $email;
		}

		return sprintf('"%s" <%s>', str_replace('"', '', $name), $email);
	}//end formatAddress()

	/**
	 * Wrap a possibly-empty string into a single-element list, or an empty list.
	 *
	 * @param string $value The value.
	 *
	 * @return string[]
	 */
	private function nonEmptyList(string $value): array {
		if ($value === '') {
			return [];
		}

		return [$value];
	}//end nonEmptyList()

	/**
	 * Resolve OpenRegister's `ObjectService` from the DI container.
	 *
	 * @return object|null
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorSourceTransport.resolveObjectService: unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObjectService()

	/**
	 * Resolve OpenConnector's `CallService` from the DI container.
	 *
	 * @return object|null
	 */
	private function resolveCallService(): ?object {
		try {
			$callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');
		} catch (Throwable $e) {
			$this->logger->error(
				'ConnectorSourceTransport.resolveCallService: unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			$this->logger->error('ConnectorSourceTransport.resolveCallService: CallService lacks call()');
			return null;
		}

		return $callService;
	}//end resolveCallService()

	/**
	 * Decode a CallLog's `response.body` for provider-id extraction.
	 *
	 * @param array<string, mixed> $callLogData CallLog payload.
	 *
	 * @return mixed Decoded JSON body, or null when it cannot be decoded.
	 */
	private function decodeCallLogResponseBody(array $callLogData): mixed {
		$response = ($callLogData['response'] ?? null);
		if (is_array($response) === false) {
			return null;
		}

		$body = ($response['body'] ?? null);
		if (is_string($body) === false || $body === '') {
			return null;
		}

		if ((string)($response['encoding'] ?? 'UTF-8') !== 'UTF-8') {
			return null;
		}

		$decoded = json_decode($body, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decodeCallLogResponseBody()

	/**
	 * Extract a provider message id from a decoded connector response body.
	 *
	 * @param mixed $result Decoded response body.
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat fallback chain over candidate
	 *  id keys across the array/object result shapes; each branch is an early return.
	 */
	private function extractProviderId(mixed $result): ?string {
		if (is_array($result) === true) {
			foreach (['providerId', 'messageId', 'id', 'MessageID'] as $key) {
				if (isset($result[$key]) === true && is_scalar($result[$key]) === true && (string)$result[$key] !== '') {
					return (string)$result[$key];
				}
			}
		}

		if (is_object($result) === true) {
			foreach (['providerId', 'messageId', 'id', 'MessageID'] as $key) {
				if (isset($result->{$key}) === true && is_scalar($result->{$key}) === true && (string)$result->{$key} !== '') {
					return (string)$result->{$key};
				}
			}
		}

		if (is_string($result) === true && $result !== '') {
			return $result;
		}

		return null;
	}//end extractProviderId()

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
}//end class
