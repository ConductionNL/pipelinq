<?php

/**
 * Golden test for LogiusConnector::authenticate and ::sendMessage.
 * UUIDv4 generation, signature compute, webhook signature verification.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-BBK-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LogiusConnector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the OAuth token request (refusals, caching, expiry) and the outbound
 * message (body, headers, signature, transport failure, missing receipt id):
 * every POST with its options, every error logged and every result or refusal.
 */
class LogiusSendGoldenTest extends TestCase {
	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Configured values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Queued replies (a body string, or null to throw); consumed per POST.
	 *
	 * @var array<int, string|null>
	 */
	private array $replies = [];

	/**
	 * A message that passes validation.
	 *
	 * @var array<string, mixed>
	 */
	private const MESSAGE = [
		'uuid' => 'msg-1',
		'id' => 'obj-1',
		'subject' => 'Uw aanvraag',
		'body' => '<p>Beste burger</p>',
		'bsnPlain' => '999990019',
		'attachments' => [
			['filename' => 'besluit.pdf', 'mime' => 'application/pdf', 'sizeBytes' => 3, 'contentBase64' => 'YWJj'],
			['mime' => 'image/png'],
		],
	];

	/**
	 * Without client credentials no token is requested.
	 *
	 * @return void
	 */
	public function testAuthenticateRefusals(): void {
		$this->assertRefused('Logius client credentials are not configured.', fn () => $this->connector()->authenticate());
		$this->config = ['logius_client_id' => 'cid'];
		$this->assertRefused('Logius client credentials are not configured.', fn () => $this->connector()->authenticate());
		$this->assertSame([], $this->trace);

		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec', 'logius_token_url' => 'https://idp/token'];
		$this->replies = [null];
		$this->assertRefused('Logius authentication failed: down', fn () => $this->connector()->authenticate());
		$this->replies = ['{"token_type":"bearer"}'];
		$this->assertRefused('Logius OAuth response missing access_token.', fn () => $this->connector()->authenticate());
		$this->replies = ['{"access_token":42}'];
		$this->assertRefused('Logius OAuth response missing access_token.', fn () => $this->connector()->authenticate());
		$this->replies = [''];
		$this->assertRefused('Logius OAuth response missing access_token.', fn () => $this->connector()->authenticate());

		$this->assertSame(
			[
				['post', 'https://idp/token', ['auth' => ['cid', 'sec'], 'body' => ['grant_type' => 'client_credentials'], 'timeout' => 30]],
				['error', 'Logius OAuth token request failed.', ['exception' => 'down']],
				['post', 'https://idp/token', ['auth' => ['cid', 'sec'], 'body' => ['grant_type' => 'client_credentials'], 'timeout' => 30]],
				['post', 'https://idp/token', ['auth' => ['cid', 'sec'], 'body' => ['grant_type' => 'client_credentials'], 'timeout' => 30]],
				['post', 'https://idp/token', ['auth' => ['cid', 'sec'], 'body' => ['grant_type' => 'client_credentials'], 'timeout' => 30]],
			],
			$this->trace
		);
	}//end testAuthenticateRefusals()

	/**
	 * A token is cached until 30 seconds before it expires; the default token URL is used.
	 *
	 * @return void
	 */
	public function testAuthenticateCachesTheToken(): void {
		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec'];
		$this->replies = ['{"access_token":"tok-1","expires_in":3600}', '{"access_token":"tok-2","expires_in":20}', '{"access_token":"tok-3"}'];
		$connector = $this->connector();

		$this->assertSame('tok-1', $connector->authenticate());
		$this->assertSame('tok-1', $connector->authenticate());
		$fresh = $this->connector();
		$this->assertSame('tok-2', $fresh->authenticate());
		$this->assertSame('tok-3', $fresh->authenticate());
		$this->assertSame('tok-3', $fresh->authenticate());
		$this->assertCount(3, $this->trace);
		$this->assertSame('https://api.logius.nl/berichtenbox/v1.7/oauth/token', $this->trace[0][1]);
	}//end testAuthenticateCachesTheToken()

	/**
	 * The outbound message: body, headers (dev signature), endpoint and receipt.
	 *
	 * @return void
	 */
	public function testSendMessage(): void {
		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec', 'logius_base_url' => 'https://bbk/v1.7/'];
		$this->replies = ['{"access_token":"tok-1"}', '{"logiusMessageId":"L-1","status":"queued"}'];

		$result = $this->connector()->sendMessage(self::MESSAGE, '', '');

		$body = [
			'messageId' => 'msg-1',
			'subject' => 'Uw aanvraag',
			'body' => '<p>Beste burger</p>',
			'attachments' => [
				['filename' => 'besluit.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 3, 'content' => 'YWJj'],
				['filename' => 'attachment', 'mimeType' => 'image/png', 'sizeBytes' => 0, 'content' => ''],
			],
			'recipient' => ['bsn' => '999990019'],
		];
		$this->assertSame(['logiusMessageId' => 'L-1', 'raw' => ['logiusMessageId' => 'L-1', 'status' => 'queued']], $result);
		$this->assertCount(2, $this->trace);
		$this->assertSame(
			[
				'post',
				'https://bbk/v1.7/messages',
				[
					'headers' => [
						'Authorization' => 'Bearer tok-1',
						'Content-Type' => 'application/json',
						'X-Message-ID' => 'msg-1',
						'X-Timestamp' => 'TS',
						'X-Signature-Algorithm' => 'RSA-SHA256',
						'X-Signature' => base64_encode(hash('sha256', json_encode($body), true)),
					],
					'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
					'timeout' => 60,
				],
			],
			$this->trace[1]
		);
	}//end testSendMessage()

	/**
	 * The message id falls back to the object id, then to a fresh UUID; the receipt id to messageId.
	 *
	 * @return void
	 */
	public function testSendMessageIdsAndReceipts(): void {
		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec'];
		$this->replies = ['{"access_token":"tok-1","expires_in":3600}', '{"messageId":"M-2"}', '{"status":"queued"}', ''];
		$connector = $this->connector();

		$noUuid = array_diff_key(self::MESSAGE, ['uuid' => 1]);
		$this->assertSame('M-2', $connector->sendMessage($noUuid, '', '')['logiusMessageId']);
		$this->assertSame('obj-1', $this->trace[1][2]['headers']['X-Message-ID']);
		$this->assertSame('https://api.logius.nl/berichtenbox/v1.7/messages', $this->trace[1][1]);

		$this->assertRefused('Logius response missing logiusMessageId.', fn () => $connector->sendMessage(array_diff_key($noUuid, ['id' => 1]), '', ''));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->trace[2][2]['headers']['X-Message-ID']);
		$this->assertRefused('Logius response missing logiusMessageId.', fn () => $connector->sendMessage(self::MESSAGE, '', ''));
	}//end testSendMessageIdsAndReceipts()

	/**
	 * A transport failure is logged with the message id and refused; an invalid message never leaves.
	 *
	 * @return void
	 */
	public function testSendMessageFailures(): void {
		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec'];
		$this->replies = ['{"access_token":"tok-1"}', null];
		$this->assertRefused('Logius sendMessage failed: down', fn () => $this->connector()->sendMessage(self::MESSAGE, '', ''));
		$this->assertSame(['error', 'Logius sendMessage transport failure.', ['exception' => 'down', 'messageId' => 'msg-1']], $this->trace[2]);

		$this->trace = [];
		$this->assertRefused('body must be non-empty XHTML strict.', fn () => $this->connector()->sendMessage(['body' => ''] + self::MESSAGE, '', ''));
		$this->assertSame([], $this->trace);

		$this->config = [];
		$this->assertRefused('Logius client credentials are not configured.', fn () => $this->connector()->sendMessage(self::MESSAGE, '', ''));
		$this->assertSame([], $this->trace);
	}//end testSendMessageFailures()

	/**
	 * With a tenant key the body is signed with it (RSA-SHA256 over the unescaped JSON).
	 *
	 * @return void
	 */
	public function testSendMessageSignsWithTheTenantKey(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $pem);
		$public = openssl_pkey_get_details($key)['key'];
		$this->config = ['logius_client_id' => 'cid', 'logius_client_secret' => 'sec'];
		$this->replies = ['{"access_token":"tok-1"}', '{"logiusMessageId":"L-9"}'];

		$this->connector()->sendMessage(self::MESSAGE, 'CERT', $pem);

		$options = $this->trace[1][2];
		$this->assertSame(1, openssl_verify($options['body'], base64_decode($options['headers']['X-Signature']), $public, OPENSSL_ALGO_SHA256));
		$this->assertRefused('Invalid tenant PKI-overheid private key.', fn () => $this->connector()->sendMessage(self::MESSAGE, 'CERT', 'not a key'));
	}//end testSendMessageSignsWithTheTenantKey()

	/**
	 * Assert the call throws a RuntimeException with the message.
	 *
	 * @param string   $message The expected message.
	 * @param callable $call    The call.
	 *
	 * @return void
	 */
	private function assertRefused(string $message, callable $call): void {
		try {
			$call();
			$this->fail('No refusal: ' . $message);
		} catch (RuntimeException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * A connector on recording doubles.
	 *
	 * @return LogiusConnector
	 */
	private function connector(): LogiusConnector {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $uri, array $options): IResponse {
				if (isset($options['headers']['X-Timestamp']) === true) {
					$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $options['headers']['X-Timestamp']);
					$options['headers']['X-Timestamp'] = 'TS';
				}

				$this->trace[] = ['post', $uri, $options];
				$reply = array_shift($this->replies);
				if ($reply === null) {
					throw new \RuntimeException('down');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($reply);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['error', $message, $context];
			}
		);

		return new LogiusConnector($clients, $appConfig, $logger);
	}//end connector()
}//end class
