<?php

/**
 * Pipelinq WhatsAppProviderClient.
 *
 * Abstraction over Meta Cloud API and BSP relay endpoints. Delegates HTTP
 * transport to OpenRegister's `MessageDispatchProvider` leaf (via
 * {@see MessageDispatchTrait}), which routes through openconnector's
 * `whatsapp-cloud-api` / `whatsapp-bsp` sources (ADR-005 — pipelinq never
 * holds a vendor SDK; `SourceService` no longer exists in OpenConnector) and
 * surfaces a small, test-friendly surface to WhatsAppAdapter.
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

use OCA\Pipelinq\Service\Provider\MessageDispatchTrait;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * WhatsAppProviderClient — Meta + BSP transport abstraction.
 *
 * Public entry points:
 * - sendTemplate(channelProvider, phoneNumber, templateName,
 *     language, parameters) — fire a template send.
 * - sendFreeForm(channelProvider, phoneNumber, body, mediaIds?) —
 *     fire a free-form send (caller has already validated the
 *     24h session window).
 * - downloadMedia(channelProvider, mediaId) — pull a media URL
 *     within Meta's 5-minute expiry window.
 * - uploadMedia(channelProvider, filePath, mimeType) — upload to
 *     Meta media API.
 * - listTemplates(channelProvider) — read template approval state
 *     for the sync job.
 * - verifySignature(channelProvider, rawBody, signature) —
 *     X-Hub-Signature-256 validation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges multiple
 * vendors through openconnector.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppProviderClient {
	use MessageDispatchTrait;

	/**
	 * Map a logical action to the vendor send path (relative to the source
	 * base URL). Send/template actions POST to `messages`; media
	 * upload/download to `media`; the template-approval read to
	 * `message_templates`.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_PATHS = [
		'send-template' => 'messages',
		'send-text' => 'messages',
		'download-media' => 'media',
		'upload-media' => 'media',
		'list-templates' => 'message_templates',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send an HSM template via the provider.
	 *
	 * @param array<string, mixed> $channelProvider Provider row.
	 * @param string $phoneNumber Recipient E.164.
	 * @param string $templateName Template external id.
	 * @param string $language Language code (e.g. `nl`).
	 * @param array<int, string> $parameters Positional template parameters.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider id.
	 *
	 * @throws TransientSmsProviderException On 5xx / network failure.
	 * @throws PermanentSmsProviderException On 4xx / config failure.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function sendTemplate(
		array $channelProvider,
		string $phoneNumber,
		string $templateName,
		string $language,
		array $parameters,
	): array {
		$template = ['name' => $templateName, 'language' => $language, 'parameters' => $parameters];
		return $this->sent(
			channelProvider: $channelProvider,
			request: ['action' => 'send-template', 'payload' => $this->templatePayload(phoneNumber: $phoneNumber, template: $template)]
		);
	}//end sendTemplate()

	/**
	 * Send a free-form text (and optional media) message.
	 *
	 * @param array<string, mixed> $channelProvider Provider row.
	 * @param string $phoneNumber Recipient E.164.
	 * @param string $body Message body.
	 * @param array<int, string> $mediaIds Optional uploaded media ids.
	 *
	 * @return array{externalMessageId: string, vendor: string} Provider id.
	 *
	 * @throws TransientSmsProviderException On transient.
	 * @throws PermanentSmsProviderException On permanent.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function sendFreeForm(
		array $channelProvider,
		string $phoneNumber,
		string $body,
		array $mediaIds = [],
	): array {
		$payload = self::freeFormPayload(phoneNumber: $phoneNumber, content: ['body' => $body, 'mediaIds' => $mediaIds]);

		return $this->sent(channelProvider: $channelProvider, request: ['action' => 'send-text', 'payload' => $payload]);
	}//end sendFreeForm()

	/**
	 * The free-form message body: text, or an image when media is attached.
	 *
	 * @param string                                       $phoneNumber Recipient E.164.
	 * @param array{body: string, mediaIds: array<int, string>} $content     The message body and optional uploaded media ids.
	 *
	 * @return array<string, mixed>
	 */
	private static function freeFormPayload(string $phoneNumber, array $content): array {
		$payload = ['messaging_product' => 'whatsapp', 'to' => $phoneNumber, 'type' => 'text', 'text' => ['body' => $content['body']]];
		if ($content['mediaIds'] !== []) {
			$payload['type'] = 'image';
			$payload['image'] = ['id' => $content['mediaIds'][0]];
		}

		return $payload;
	}//end freeFormPayload()

	/**
	 * The template message body: its name, language and one text parameter
	 * per value, in order.
	 *
	 * @param string                                                          $phoneNumber Recipient E.164.
	 * @param array{name: string, language: string, parameters: array<string>} $template    The template.
	 *
	 * @return array<string, mixed>
	 */
	private function templatePayload(string $phoneNumber, array $template): array {
		$parameters = array_values(
			array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $template['parameters'])
		);

		return [
			'messaging_product' => 'whatsapp',
			'to' => $phoneNumber,
			'type' => 'template',
			'template' => [
				'name' => $template['name'],
				'language' => ['code' => $template['language']],
				'components' => [['type' => 'body', 'parameters' => $parameters]],
			],
		];
	}//end templatePayload()

	/**
	 * Dispatch one send and answer the provider's message id and vendor.
	 *
	 * @param array<string, mixed>                                 $channelProvider Provider row.
	 * @param array{action: string, payload: array<string, mixed>} $request         The action and its body.
	 *
	 * @return array{externalMessageId: string, vendor: string}
	 *
	 * @throws TransientSmsProviderException On transient.
	 * @throws PermanentSmsProviderException On permanent.
	 */
	private function sent(array $channelProvider, array $request): array {
		$result = $this->dispatch(channelProvider: $channelProvider, request: $request);

		return [
			'externalMessageId' => $this->extractWamid(result: $result),
			'vendor' => (string)($channelProvider['vendor'] ?? ''),
		];
	}//end sent()

	/*
	 * NO MEDIA TRANSFER HERE.
	 *
	 * `downloadMedia()` and `uploadMedia()` used to sit between `sendFreeForm()`
	 * and `listTemplates()`. Neither had a caller: `uploadMedia()` had none at
	 * all, and `downloadMedia()`'s only one was `MediaAttachmentService`, whose
	 * whole class was equally unreferenced and is removed in the same change.
	 * The WhatsApp media pipeline was never wired into a live path, and no
	 * current spec requires it — the only reference is the archived
	 * `whatsapp-sms-channel-adapter` task list.
	 */

	/**
	 * List remote templates for the approval-sync job.
	 *
	 * @param array<string, mixed> $channelProvider Provider row.
	 *
	 * @return array<int, array<string, mixed>> Template rows.
	 *
	 * @throws TransientSmsProviderException On transient.
	 * @throws PermanentSmsProviderException On permanent.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function listTemplates(array $channelProvider): array {
		$result = $this->dispatch(
			channelProvider: $channelProvider,
			request: ['action' => 'list-templates', 'payload' => []],
		);

		if (isset($result['data']) === true && is_array($result['data']) === true) {
			return $result['data'];
		}

		return [];
	}//end listTemplates()

	/**
	 * Verify a Meta X-Hub-Signature-256 header.
	 *
	 * @param array<string, mixed> $channelProvider Provider row (carries webhookSecret).
	 * @param string $rawBody Raw body.
	 * @param string $signature Header value (sha256=...).
	 *
	 * @return bool True when authentic.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function verifySignature(array $channelProvider, string $rawBody, string $signature): bool {
		$secret = (string)($channelProvider['webhookSecret'] ?? '');
		if ($secret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, self::signatureHex(signature: $signature));
	}//end verifySignature()

	/**
	 * The hex digest of a signature header, without its `sha256=` prefix.
	 *
	 * @param string $signature Header value (sha256=...).
	 *
	 * @return string The digest.
	 */
	private static function signatureHex(string $signature): string {
		if (str_starts_with($signature, 'sha256=') === true) {
			return substr($signature, 7);
		}

		return $signature;
	}//end signatureHex()

	/**
	 * Dispatch a call via the OpenRegister MessageDispatchProvider leaf.
	 *
	 * The `channelProvider.sourceId` (a.k.a. `openconnectorSourceId`) is the
	 * OpenConnector source slug (`whatsapp-cloud-api` / `whatsapp-bsp`) the
	 * leaf routes through. The logical `$action` is mapped to the vendor send
	 * path; the connection + credentials live in OpenConnector (ADR-022).
	 *
	 * @param array<string, mixed>                                 $channelProvider Provider row.
	 * @param array{action: string, payload: array<string, mixed>} $request         The action name and its payload.
	 *
	 * @return array<string, mixed> Result (raw provider response).
	 *
	 * @throws TransientSmsProviderException On transient.
	 * @throws PermanentSmsProviderException On permanent.
	 */
	private function dispatch(array $channelProvider, array $request): array {
		$sourceId = (string)($channelProvider['sourceId'] ?? ($channelProvider['openconnectorSourceId'] ?? ''));
		if ($sourceId === '') {
			throw new PermanentSmsProviderException(message: 'WhatsApp provider source not configured');
		}

		$path = self::ACTION_PATHS[$request['action']] ?? 'messages';

		return $this->dispatchViaLeaf(
			source: $sourceId,
			body: $request['payload'],
			path: $path,
		);
	}//end dispatch()

	/**
	 * Extract a Meta wamid from a send response.
	 *
	 * @param array<string, mixed> $result Provider response.
	 *
	 * @return string wamid or empty.
	 */
	private function extractWamid(array $result): string {
		if (isset($result['messages']) === true && is_array($result['messages']) === true) {
			$first = $result['messages'][0] ?? null;
			if (is_array($first) === true && isset($first['id']) === true) {
				return (string)$first['id'];
			}
		}

		if (isset($result['externalMessageId']) === true) {
			return (string)$result['externalMessageId'];
		}

		return (string)($result['id'] ?? '');
	}//end extractWamid()
}//end class
