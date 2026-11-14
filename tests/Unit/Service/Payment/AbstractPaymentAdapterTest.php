<?php

/**
 * Unit tests for the shared payment-adapter helpers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\CurlHttpTransport;
use OCA\Pipelinq\Service\Payment\HttpTransport;
use OCA\Pipelinq\Service\Payment\PspCall;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the amount formatting, signature comparison, config reading, transport
 * resolution and reply interpretation every PSP adapter inherits.
 *
 * @covers \OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter
 * @uses   \OCA\Pipelinq\Service\Payment\CurlHttpTransport
 * @uses   \OCA\Pipelinq\Service\Payment\PspCall
 */
class AbstractPaymentAdapterTest extends TestCase {

	/**
	 * A minimal concrete adapter that exposes the protected helpers.
	 *
	 * @param array<string, mixed> $credentials The credentials.
	 * @param array<string, mixed> $config      The config.
	 * @param LoggerInterface|null $logger      The logger.
	 * @param HttpTransport|null   $http        The transport.
	 *
	 * @return AbstractPaymentAdapter The adapter.
	 */
	private function adapter(
		array $credentials = [],
		array $config = [],
		?LoggerInterface $logger = null,
		?HttpTransport $http = null,
	): AbstractPaymentAdapter {
		return new class($credentials, $config, $logger ?? $this->createMock(LoggerInterface::class), $http) extends AbstractPaymentAdapter {
			/**
			 * Invoke a protected helper.
			 *
			 * @param string $method The helper name.
			 * @param mixed  ...$args The named arguments.
			 *
			 * @return mixed The helper's result.
			 */
			public function expose(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}

			public function getName(): string {
				return 'test';
			}

			protected function label(): string {
				return 'TestPSP';
			}

			protected function authHeaders(): array {
				return ['Authorization' => 'Bearer x'];
			}

			public function initiate(array $transactionData, float $amount, string $paymentMethod): array {
				return [];
			}

			public function capture(string $sessionId): array {
				return [];
			}

			public function refund(string $sessionId, string $reason): array {
				return [];
			}

			public function validateWebhook(string $rawPayload, string $signature): bool {
				return false;
			}

			public function parseWebhook(array $payload): array {
				return [];
			}

			public function testConnection(): array {
				return [];
			}
		};
	}//end adapter()

	/**
	 * Cents render as a signed two-decimal string, negatives included.
	 *
	 * @return void
	 */
	public function testCentsRenderAsDecimals(): void {
		$adapter = $this->adapter();

		$this->assertSame('12.05', $adapter->expose('centsToDecimal', cents: 1205));
		$this->assertSame('0.07', $adapter->expose('centsToDecimal', cents: 7));
		$this->assertSame('-3.50', $adapter->expose('centsToDecimal', cents: -350));
		$this->assertSame('-0.01', $adapter->expose('centsToDecimal', cents: -1));
		$this->assertSame(1999, $adapter->expose('toCents', amount: 19.99));
	}//end testCentsRenderAsDecimals()

	/**
	 * An empty signature on either side never matches; equal digests do.
	 *
	 * @return void
	 */
	public function testSignaturesCompare(): void {
		$adapter = $this->adapter();

		$this->assertTrue($adapter->expose('safeEquals', expected: 'abc', provided: 'abc'));
		$this->assertFalse($adapter->expose('safeEquals', expected: 'abc', provided: 'abd'));
		$this->assertFalse($adapter->expose('safeEquals', expected: '', provided: ''));
		$this->assertFalse($adapter->expose('safeEquals', expected: 'abc', provided: ''));
		$this->assertFalse($adapter->expose('safeEquals', expected: '', provided: 'abc'));
	}//end testSignaturesCompare()

	/**
	 * Without an injected transport the adapter builds one cURL transport and keeps it.
	 *
	 * @return void
	 */
	public function testTheTransportDefaultsToCurlOnce(): void {
		$adapter = $this->adapter();

		$first = $adapter->expose('transport');

		$this->assertInstanceOf(CurlHttpTransport::class, $first);
		$this->assertSame($first, $adapter->expose('transport'));

		$injected = $this->createMock(HttpTransport::class);
		$adapter->setHttpTransport($injected);
		$this->assertSame($injected, $adapter->expose('transport'));
	}//end testTheTransportDefaultsToCurlOnce()

	/**
	 * Credentials and config read strings only, falling back otherwise; live switches the URL.
	 *
	 * @return void
	 */
	public function testCredentialsAndConfigAreReadAsStrings(): void {
		$adapter = $this->adapter(
			credentials: ['apiKey' => 'k1', 'port' => 443],
			config: ['environment' => 'live', 'merchant' => ['x']]
		);

		$this->assertSame('k1', $adapter->expose('credential', key: 'apiKey'));
		$this->assertSame('', $adapter->expose('credential', key: 'port'));
		$this->assertSame('', $adapter->expose('credential', key: 'missing'));
		$this->assertSame('dflt', $adapter->expose('configValue', key: 'merchant', default: 'dflt'));
		$this->assertSame('dflt', $adapter->expose('configValue', key: 'missing', default: 'dflt'));
		$this->assertFalse($adapter->expose('isSandbox'));
		$this->assertSame('https://live', $adapter->expose('environmentUrl', sandboxUrl: 'https://sb', liveUrl: 'https://live'));

		$sandbox = $this->adapter();
		$this->assertTrue($sandbox->expose('isSandbox'));
		$this->assertSame('https://sb', $sandbox->expose('environmentUrl', sandboxUrl: 'https://sb', liveUrl: 'https://live'));
	}//end testCredentialsAndConfigAreReadAsStrings()

	/**
	 * A body makes the call a JSON POST; no body is a GET; a throwing transport is logged and null.
	 *
	 * @return void
	 */
	public function testSendChoosesTheMethodAndSwallowsTransportErrors(): void {
		$reply = ['status' => 201, 'body' => ['id' => 'p1'], 'raw' => '{"id":"p1"}'];
		$http = $this->createMock(HttpTransport::class);
		$http->expects($this->exactly(2))
			->method('request')
			->willReturnCallback(
				function (string $method, string $url, array $headers, ?string $body) use ($reply): array {
					if ($body === null) {
						$this->assertSame('GET', $method);
						$this->assertSame(['Authorization' => 'Bearer x'], $headers);
					} else {
						$this->assertSame('POST', $method);
						$this->assertSame(['Authorization' => 'Bearer x', 'Content-Type' => 'application/json'], $headers);
						$this->assertSame('{"a":1}', $body);
					}

					$this->assertSame('https://psp/pay', $url);
					return $reply;
				}
			);
		$adapter = $this->adapter(http: $http);
		$call = new PspCall(url: 'https://psp/pay', context: 'create payment');

		$this->assertSame($reply, $adapter->expose('send', call: $call, body: '{"a":1}'));
		$this->assertSame($reply, $adapter->expose('send', call: $call, body: null));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq POS payment [test]: create payment',
				['provider' => 'test', 'class' => RuntimeException::class]
			);
		$failing = $this->createMock(HttpTransport::class);
		$failing->method('request')->willThrowException(new RuntimeException('secret-bearing message'));

		$this->assertNull($this->adapter(logger: $logger, http: $failing)->expose('send', call: $call, body: null));
	}//end testSendChoosesTheMethodAndSwallowsTransportErrors()

	/**
	 * Replies map to a lost connection, an HTTP error, or no error; and to connection verdicts.
	 *
	 * @return void
	 */
	public function testRepliesAreInterpreted(): void {
		$adapter = $this->adapter();
		$ok = ['status' => 200, 'body' => [], 'raw' => ''];
		$redirect = ['status' => 302, 'body' => [], 'raw' => ''];
		$denied = ['status' => 401, 'body' => [], 'raw' => ''];

		$this->assertSame('Verbinding met betalingsprovider verbroken', $adapter->expose('replyError', response: null));
		$this->assertSame('TestPSP returned HTTP 401', $adapter->expose('replyError', response: $denied));
		$this->assertSame('TestPSP returned HTTP 302', $adapter->expose('replyError', response: $redirect));
		$this->assertNull($adapter->expose('replyError', response: $ok));

		$this->assertSame(
			['status' => 'error', 'message' => 'Verbinding met TestPSP mislukt'],
			$adapter->expose('connectionVerdict', response: null)
		);
		$this->assertSame(
			['status' => 'ok', 'message' => 'Verbinding met TestPSP succesvol'],
			$adapter->expose('connectionVerdict', response: $ok)
		);
		$this->assertSame(
			['status' => 'error', 'message' => 'TestPSP gaf HTTP 401 terug'],
			$adapter->expose('connectionVerdict', response: $denied)
		);
	}//end testRepliesAreInterpreted()

	/**
	 * The result shapes carry the session, status and error the callers read.
	 *
	 * @return void
	 */
	public function testResultShapes(): void {
		$adapter = $this->adapter();

		$this->assertSame(
			['sessionId' => '', 'redirectUrl' => null, 'status' => 'failed', 'error' => 'no'],
			$adapter->expose('failedInitiate', message: 'no')
		);
		$this->assertSame(
			['sessionId' => 's1', 'status' => 'failed', 'error' => 'no'],
			$adapter->expose('failedCapture', sessionId: 's1', message: 'no')
		);
		$this->assertSame(
			['sessionId' => 's1', 'refundId' => '', 'status' => 'failed', 'error' => 'no'],
			$adapter->expose('failedRefund', sessionId: 's1', message: 'no')
		);
		$this->assertSame(
			['sessionId' => 's1', 'redirectUrl' => 'https://pay', 'status' => 'pending'],
			$adapter->expose('pending', sessionId: 's1', redirectUrl: 'https://pay')
		);
		$this->assertSame(
			['sessionId' => 's1', 'status' => 'paid', 'error' => null],
			$adapter->expose('settled', sessionId: 's1', status: 'paid')
		);
		$this->assertSame(
			['sessionId' => 's1', 'refundId' => 'r1', 'status' => 'refunded', 'error' => null],
			$adapter->expose('refunded', sessionId: 's1', refundId: 'r1')
		);
		$this->assertSame(['status' => 'error', 'message' => 'set a key'], $adapter->expose('notConfigured', message: 'set a key'));
		$this->assertSame('Refund via Pipelinq POS', $adapter->expose('refundReason', reason: ''));
		$this->assertSame('damaged', $adapter->expose('refundReason', reason: 'damaged'));
	}//end testResultShapes()
}//end class
