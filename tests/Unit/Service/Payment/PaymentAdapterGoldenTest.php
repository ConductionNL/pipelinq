<?php

/**
 * Pipelinq payment adapter golden test.
 *
 * Runs every outbound operation of the four payment adapters (initiate, capture,
 * refund, testConnection) through every outcome — not configured, invalid input,
 * accepted, an HTTP error, a transport failure — and compares the requests sent,
 * the results and the warnings logged with a recorded golden file, so the
 * adapters can share their request plumbing without moving an outcome.
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
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\HttpTransport;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentAdapterGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/PaymentAdapterGolden.json';

	/**
	 * Full credentials for every provider.
	 *
	 * @var array<string, string>
	 */
	private const CREDENTIALS = [
		'apiKey' => 'key-1',
		'apiSecret' => 'secret-1',
		'webhookSecret' => 'hook-1',
	];

	/**
	 * Full configuration for every provider.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG = [
		'merchantAccount' => 'MERCHANT',
		'terminalId' => 'TERM-1',
		'merchantId' => 'MID-1',
		'environment' => 'live',
	];

	/**
	 * A reply body carrying every field any adapter reads.
	 *
	 * @var array<string, mixed>
	 */
	private const REPLY = [
		'id' => 'pay_1',
		'pspReference' => 'PSP-1',
		'reference' => 'REF-1',
		'status' => 'paid',
		'client_secret' => 'cs_1',
		'url' => 'https://pay.example/checkout',
		'_links' => ['checkout' => ['href' => 'https://pay.example/mollie']],
		'returnUrl' => 'https://pay.example/return',
		'payUrl' => 'https://pay.example/ccv',
	];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach (['mollie', 'adyen', 'ccv', 'stripe'] as $provider) {
			foreach ($this->cases() as $name => $case) {
				$actual[$provider . ': ' . $name] = $this->runCase($provider, $case);
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('PAYMENT_ADAPTER_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * The cases: operation, arguments, credentials, config and transport behaviour.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$tx = ['reference' => 'POS-42', 'id' => 'tx-42'];
		$ok = ['status' => 201, 'body' => self::REPLY, 'raw' => 'x'];
		$sandbox = ['environment' => 'sandbox'] + self::CONFIG;
		$cases = [];
		foreach (['initiate' => [$tx, 12.34, 'ideal'], 'capture' => ['pay_1'], 'refund' => ['pay_1', 'Wrong size'], 'testConnection' => []] as $op => $args) {
			$cases[$op . ', not configured'] = ['op' => $op, 'args' => $args, 'credentials' => [], 'config' => [], 'reply' => $ok];
			$cases[$op . ', accepted'] = ['op' => $op, 'args' => $args, 'reply' => $ok];
			$cases[$op . ', accepted in sandbox'] = ['op' => $op, 'args' => $args, 'config' => $sandbox, 'reply' => ['status' => 200, 'body' => [], 'raw' => '']];
			$cases[$op . ', HTTP error'] = ['op' => $op, 'args' => $args, 'reply' => ['status' => 422, 'body' => ['error' => 'no'], 'raw' => '']];
			$cases[$op . ', status 199'] = ['op' => $op, 'args' => $args, 'reply' => ['status' => 199, 'body' => self::REPLY, 'raw' => '']];
			$cases[$op . ', status 300'] = ['op' => $op, 'args' => $args, 'reply' => ['status' => 300, 'body' => self::REPLY, 'raw' => '']];
			$cases[$op . ', transport fails'] = ['op' => $op, 'args' => $args, 'reply' => null];
		}

		$cases['initiate, zero amount'] = ['op' => 'initiate', 'args' => [$tx, 0.004, 'card'], 'reply' => $ok];
		$cases['initiate, other methods'] = ['op' => 'initiate', 'args' => [['id' => 'tx-1', 'reference' => ''], 5.0, 'creditcard'], 'reply' => $ok];
		$cases['initiate, bancontact'] = ['op' => 'initiate', 'args' => [['reference' => 'R'], 5.0, 'bancontact'], 'reply' => $ok];
		$cases['initiate, only api key'] = ['op' => 'initiate', 'args' => [$tx, 1.0, 'card'], 'credentials' => ['apiKey' => 'key-only'], 'reply' => $ok];
		$cases['initiate, no terminal or merchant'] = ['op' => 'initiate', 'args' => [$tx, 1.0, 'card'], 'config' => ['environment' => 'live'], 'reply' => $ok];
		$cases['capture, no session'] = ['op' => 'capture', 'args' => [''], 'reply' => $ok];
		$cases['capture, odd status'] = ['op' => 'capture', 'args' => ['pay 1/2'], 'reply' => ['status' => 200, 'body' => ['status' => 'weird'], 'raw' => '']];
		$cases['refund, no session'] = ['op' => 'refund', 'args' => ['', 'x'], 'reply' => $ok];
		$cases['refund, no reason'] = ['op' => 'refund', 'args' => ['pay 1/2', ''], 'reply' => $ok];
		$cases['testConnection, HTTP 401'] = ['op' => 'testConnection', 'args' => [], 'reply' => ['status' => 401, 'body' => [], 'raw' => '']];
		$cases['testConnection, HTTP 403'] = ['op' => 'testConnection', 'args' => [], 'reply' => ['status' => 403, 'body' => [], 'raw' => '']];
		$cases['testConnection, only api key'] = ['op' => 'testConnection', 'args' => [], 'credentials' => ['apiKey' => 'key-only'], 'reply' => $ok];
		return $cases;
	}//end cases()

	/**
	 * Run one case on one provider.
	 *
	 * @param string               $provider The provider.
	 * @param array<string, mixed> $case     The case.
	 *
	 * @return array<string, mixed> The requests, the result and the warnings.
	 */
	private function runCase(string $provider, array $case): array {
		$transport = new class($case['reply']) implements HttpTransport {
			/**
			 * The requests made.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $requests = [];

			/**
			 * @param array<string, mixed>|null $reply The reply, or null to throw.
			 */
			public function __construct(private ?array $reply) {
			}

			/**
			 * @param string                $method  The method.
			 * @param string                $url     The URL.
			 * @param array<string, string> $headers The headers.
			 * @param string|null           $body    The body.
			 *
			 * @return array{status: int, body: array<string, mixed>, raw: string}
			 */
			public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
				$this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
				if ($this->reply === null) {
					throw new \RuntimeException('transport down');
				}

				return $this->reply;
			}
		};

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$adapter = $this->adapter($provider, ($case['credentials'] ?? self::CREDENTIALS), ($case['config'] ?? self::CONFIG), $logger);
		$adapter->setHttpTransport($transport);
		$result = $adapter->{$case['op']}(...$case['args']);

		return ['requests' => $transport->requests, 'result' => $result, 'warnings' => $warnings];
	}//end runCase()

	/**
	 * Build one adapter.
	 *
	 * @param string                $provider    The provider.
	 * @param array<string, string> $credentials The credentials.
	 * @param array<string, string> $config      The config.
	 * @param LoggerInterface       $logger      The logger.
	 *
	 * @return AbstractPaymentAdapter
	 */
	private function adapter(string $provider, array $credentials, array $config, LoggerInterface $logger): AbstractPaymentAdapter {
		return match ($provider) {
			'mollie' => new MollieAdapter(credentials: $credentials, config: $config, logger: $logger),
			'adyen' => new AdyenAdapter(credentials: $credentials, config: $config, logger: $logger),
			'ccv' => new CcvAdapter(credentials: $credentials, config: $config, logger: $logger),
			'stripe' => new StripeAdapter(credentials: $credentials, config: $config, logger: $logger),
		};
	}//end adapter()
}//end class
