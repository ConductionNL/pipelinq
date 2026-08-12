<?php

/**
 * Pipelinq BrokerHttpTransport unit tests.
 *
 * Pins the invariants that make the PSP-key migration real rather than cosmetic:
 * the host is the broker's to choose, auth headers are the broker's to set, and there is
 * no path back to a direct, app-authenticated PSP call.
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
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\BrokerHttpTransport;
use OCA\Pipelinq\Service\Payment\CurlHttpTransport;
use OCA\Pipelinq\Service\Payment\HttpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class BrokerHttpTransportTest extends TestCase {
	/**
	 * The transport implements the same seam the adapters already use, so swapping it in
	 * needs no adapter change.
	 *
	 * @return void
	 */
	public function testItIsAnHttpTransport(): void {
		$transport = new BrokerHttpTransport(
			credentialId: 'cred-1',
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(HttpTransport::class, $transport);
	}//end testItIsAnHttpTransport()

	/**
	 * A full URL is reduced to a path + query.
	 *
	 * The broker prepends its own host-locked base URL. Passing the whole URL would
	 * silently produce a doubled host — and, more to the point, an adapter that can name
	 * the host can name a different one.
	 *
	 * @return void
	 */
	public function testTheHostIsDiscardedAndOnlyThePathSurvives(): void {
		$transport = new BrokerHttpTransport(
			credentialId: 'cred-1',
			logger: $this->createMock(LoggerInterface::class)
		);

		$toPath = (new ReflectionClass($transport))->getMethod('toPath');
		$toPath->setAccessible(true);

		$this->assertSame(
			'/v2/payments',
			$toPath->invoke($transport, 'https://api.mollie.com/v2/payments')
		);
		$this->assertSame(
			'/v2/payments/tr_123?embed=refunds',
			$toPath->invoke($transport, 'https://api.mollie.com/v2/payments/tr_123?embed=refunds')
		);

		// An attacker-controlled host does not survive either — only its path does.
		$this->assertSame(
			'/v2/payments',
			$toPath->invoke($transport, 'https://evil.example.com/v2/payments')
		);
	}//end testTheHostIsDiscardedAndOnlyThePathSurvives()

	/**
	 * Every auth header the adapter builds is dropped before the broker call.
	 *
	 * The broker discards caller-supplied auth headers anyway; dropping them here means a
	 * stale `Authorization` line can never look like it is doing something.
	 *
	 * @return void
	 */
	public function testAuthHeadersAreStripped(): void {
		$transport = new BrokerHttpTransport(
			credentialId: 'cred-1',
			logger: $this->createMock(LoggerInterface::class)
		);

		$strip = (new ReflectionClass($transport))->getMethod('stripBrokerOwnedHeaders');
		$strip->setAccessible(true);

		$out = $strip->invoke(
			$transport,
			[
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . AbstractPaymentAdapter::BROKER_MANAGED_SECRET,
				'X-API-Key' => 'anything',
				'apikey' => 'anything',
				'Idempotency-Key' => 'abc',
			]
		);

		$this->assertSame(
			['Content-Type' => 'application/json', 'Idempotency-Key' => 'abc'],
			$out
		);
	}//end testAuthHeadersAreStripped()

	/**
	 * No credential → no call. There is deliberately no app-held fallback to reach for.
	 *
	 * @return void
	 */
	public function testItFailsClosedWithoutACredential(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('error');

		$transport = new BrokerHttpTransport(credentialId: '', logger: $logger);

		$result = $transport->request(method: 'POST', url: 'https://api.mollie.com/v2/payments');

		$this->assertSame(0, $result['status']);
		$this->assertSame([], $result['body']);
	}//end testItFailsClosedWithoutACredential()

	/**
	 * The tripwire: the broker-managed placeholder must never reach the wire.
	 *
	 * If it ever arrives at the direct-cURL transport, the call has been routed around
	 * the broker — which would mean somebody reintroduced a direct, app-authenticated PSP
	 * call. Refuse to send it, loudly, rather than put a meaningless bearer token on the
	 * wire and carry on.
	 *
	 * @return void
	 */
	public function testCurlTransportRefusesToSendTheBrokerManagedPlaceholder(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$curl = new CurlHttpTransport(logger: $logger);

		$result = $curl->request(
			method: 'POST',
			url: 'https://api.mollie.com/v2/payments',
			headers: ['Authorization' => 'Bearer ' . AbstractPaymentAdapter::BROKER_MANAGED_SECRET],
			body: '{}'
		);

		$this->assertSame(0, $result['status'], 'the request must not have been sent');
		$this->assertSame('', $result['raw']);
	}//end testCurlTransportRefusesToSendTheBrokerManagedPlaceholder()

	/**
	 * The placeholder is not secret-shaped — it is a label, and it is meant to be
	 * recognisable in a log if it ever escapes.
	 *
	 * @return void
	 */
	public function testThePlaceholderIsNotSecretShaped(): void {
		$this->assertSame(
			'__managed_by_credential_broker__',
			AbstractPaymentAdapter::BROKER_MANAGED_SECRET
		);
	}//end testThePlaceholderIsNotSecretShaped()
}//end class
