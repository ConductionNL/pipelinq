<?php

/**
 * Characterisation tests for PosPaymentService::initiatePayment() and refundPayment().
 *
 * Pins who may refund and with what, which transactions may be started or
 * refunded, that a provider failure never changes the transaction, and the
 * patch and event of a successful start and refund.
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
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for starting and refunding a POS payment.
 */
class PosPaymentInitiateRefundTest extends TestCase {

	/**
	 * Transactions by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $transactions = [];

	/**
	 * Saved transactions, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Provider calls, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $providerCalls = [];

	/**
	 * Refund events emitted, as [transaction, refundId, reason].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	private array $refundEvents = [];

	/**
	 * A confirmed transaction is started with the provider and gets the session.
	 *
	 * @return void
	 */
	public function testAConfirmedTransactionIsStarted(): void {
		$this->transactions['tx-1'] = ['status' => 'confirmed', 'total' => '12.50', 'reference' => 'R1'];
		$service = $this->service(result: ['status' => 'pending', 'sessionId' => 'tr_9', 'checkoutUrl' => 'https://pay']);

		$outcome = $service->initiatePayment('tx-1', 'mollie', 'ideal');

		$this->assertSame([['initiate', [['status' => 'confirmed', 'total' => '12.50', 'reference' => 'R1'], 12.5, 'ideal']]], $this->providerCalls);
		$this->assertSame(
			['status' => 'confirmed', 'total' => '12.50', 'reference' => 'R1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_9', 'paymentStatus' => 'pending', 'paymentMethod' => 'ideal'],
			$this->saved[0]
		);
		$this->assertSame(['transaction', 'payment'], array_keys($outcome));
		$this->assertSame('tr_9', $outcome['payment']['sessionId']);
		$this->assertSame('tr_9', $outcome['transaction']['paymentSessionId']);
	}//end testAConfirmedTransactionIsStarted()

	/**
	 * A failed start leaves the transaction untouched.
	 *
	 * @return void
	 */
	public function testAFailedStartChangesNothing(): void {
		$this->transactions['tx-1'] = ['status' => 'confirmed', 'total' => 3];
		$service = $this->service(result: ['status' => 'failed', 'error' => 'declined']);

		$outcome = $service->initiatePayment('tx-1', 'ccv', 'card');

		$this->assertSame(['transaction' => ['status' => 'confirmed', 'total' => 3], 'payment' => ['status' => 'failed', 'error' => 'declined']], $outcome);
		$this->assertSame([], $this->saved);
	}//end testAFailedStartChangesNothing()

	/**
	 * A manager refunds a settled payment with a reason; the transaction
	 * records the refund and the refund event is emitted.
	 *
	 * @return void
	 */
	public function testAManagerRefundsASettledPayment(): void {
		$this->transactions['tx-2'] = ['paymentStatus' => 'captured', 'paymentProvider' => 'adyen', 'paymentSessionId' => 'sess-2'];
		$service = $this->service(result: ['status' => 'refunded', 'refundId' => 'rf-1'], manager: true);

		$outcome = $service->refundPayment('tx-2', 'Wrong item', 'boss');

		$this->assertSame([['refund', ['sess-2', 'Wrong item']]], $this->providerCalls);
		$this->assertSame(['refunded', 'Wrong item'], [$this->saved[0]['paymentStatus'], $this->saved[0]['refundReason']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->saved[0]['refundedAt']);
		$this->assertSame([[$this->saved[0], 'rf-1', 'Wrong item']], $this->refundEvents);
		$this->assertSame(['transaction' => $this->saved[0], 'payment' => ['status' => 'refunded', 'refundId' => 'rf-1']], $outcome);
	}//end testAManagerRefundsASettledPayment()

	/**
	 * A failed refund leaves the transaction untouched and emits nothing.
	 *
	 * @return void
	 */
	public function testAFailedRefundChangesNothing(): void {
		$this->transactions['tx-2'] = ['paymentStatus' => 'settled', 'paymentProvider' => 'adyen', 'paymentSessionId' => 'sess-2'];
		$service = $this->service(result: ['status' => 'failed'], manager: true);

		$outcome = $service->refundPayment('tx-2', 'x', 'boss');

		$this->assertSame(['transaction' => $this->transactions['tx-2'], 'payment' => ['status' => 'failed']], $outcome);
		$this->assertSame([[], []], [$this->saved, $this->refundEvents]);
	}//end testAFailedRefundChangesNothing()

	/**
	 * The refusals, in the order they are checked.
	 *
	 * @return void
	 */
	public function testTheRefusalsInOrder(): void {
		$this->transactions['tx-3'] = ['paymentStatus' => 'pending', 'paymentProvider' => 'adyen', 'paymentSessionId' => 's'];
		$this->transactions['tx-4'] = ['paymentStatus' => 'settled', 'paymentProvider' => '', 'paymentSessionId' => 's'];
		$this->transactions['tx-5'] = ['paymentStatus' => 'settled', 'paymentProvider' => 'adyen', 'paymentSessionId' => ''];

		$this->assertRefused(fn () => $this->service(result: [], manager: false)->refundPayment('tx-3', '', 'clerk'), OCSForbiddenException::class, 'Manager permission required');
		$this->assertRefused(fn () => $this->service(result: [], manager: true)->refundPayment('tx-missing', '  ', 'boss'), OCSBadRequestException::class, 'A reason is required to reverse a payment');
		$this->assertRefused(fn () => $this->service(result: [], manager: true)->refundPayment('tx-3', 'r', 'boss'), OCSBadRequestException::class, 'Transaction payment not settled');
		$this->assertRefused(fn () => $this->service(result: [], manager: true)->refundPayment('tx-4', 'r', 'boss'), OCSBadRequestException::class, 'No payment session to refund');
		$this->assertRefused(fn () => $this->service(result: [], manager: true)->refundPayment('tx-5', 'r', 'boss'), OCSBadRequestException::class, 'No payment session to refund');
		$this->assertSame([[], []], [$this->providerCalls, $this->saved]);
	}//end testTheRefusalsInOrder()

	/**
	 * Assert a call is refused with this exception and message.
	 *
	 * @param callable     $call      The call.
	 * @param class-string $exception The exception class.
	 * @param string       $message   The message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $exception, string $message): void {
		try {
			$call();
			$this->fail('expected ' . $exception);
		} catch (\Exception $e) {
			$this->assertSame([$exception, $message], [get_class($e), $e->getMessage()]);
		}
	}//end assertRefused()

	/**
	 * Record a provider call.
	 *
	 * @param string            $method    The provider method.
	 * @param array<int, mixed> $arguments Its arguments.
	 *
	 * @return void
	 */
	public function recordProviderCall(string $method, array $arguments): void {
		$this->providerCalls[] = [$method, $arguments];
	}//end recordProviderCall()

	/**
	 * The service over an in-memory transaction store, with the provider
	 * lookup and the refund event stubbed.
	 *
	 * @param array<string, mixed> $result  What the provider answers.
	 * @param bool                 $manager Whether the caller is a POS manager.
	 *
	 * @return PosPaymentService
	 */
	private function service(array $result, bool $manager = false): PosPaymentService {
		$store = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param PosPaymentInitiateRefundTest $test The test holding the transactions.
			 */
			public function __construct(private PosPaymentInitiateRefundTest $test) {
			}

			/**
			 * Find a transaction.
			 *
			 * @param int|string        $id       The id.
			 * @param array<mixed>|null $_extend  Extend.
			 * @param bool              $files    Files.
			 * @param mixed             $register The register.
			 * @param mixed             $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?array {
				return ($this->test->transactions[(string)$id] ?? null);
			}

			/**
			 * Save a transaction.
			 *
			 * @param array<string, mixed> $object The transaction.
			 * @param array<int, mixed>    $extend Extend.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend = [], $register = null, $schema = null, ?string $uuid = null): array {
				$this->test->saved[] = $object;
				$this->test->transactions[(string)$uuid] = $object;
				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'pipelinq', 'posTransaction_schema' => 'posTransaction'][$key] ?? $default)
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn($manager);

		$provider = $this->createMock(PaymentProviderInterface::class);
		foreach (['initiate', 'refund'] as $method) {
			$provider->method($method)->willReturnCallback(
				function (...$arguments) use ($method, $result): array {
					$this->recordProviderCall(method: $method, arguments: $arguments);
					return $result;
				}
			);
		}

		$service = $this->getMockBuilder(PosPaymentService::class)
			->setConstructorArgs([$container, $appConfig, $this->createMock(ICrypto::class), $groups, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)])
			->onlyMethods(['getPaymentProvider', 'emitRefundedEvent'])
			->getMock();
		$service->method('getPaymentProvider')->willReturn($provider);
		$service->method('emitRefundedEvent')->willReturnCallback(
			function (array $transaction, string $refundId, string $reason): void {
				$this->refundEvents[] = [$transaction, $refundId, $reason];
			}
		);
		return $service;
	}//end service()
}//end class
