<?php

/**
 * Unit tests for the webhook guards and parsers of the payment adapters.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/StubHttpTransport.php';

/**
 * Every adapter refuses a webhook without payload, signature or a usable
 * secret; the CCV and Adyen parsers map their payloads; Adyen accepts a
 * non-hex secret as literal bytes.
 *
 * @covers \OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter
 * @covers \OCA\Pipelinq\Service\Payment\AdyenAdapter
 * @covers \OCA\Pipelinq\Service\Payment\CcvAdapter
 * @covers \OCA\Pipelinq\Service\Payment\MollieAdapter
 * @covers \OCA\Pipelinq\Service\Payment\StripeAdapter
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 */
class PaymentWebhookGuardsTest extends TestCase {

	/**
	 * Build an adapter of the given class.
	 *
	 * @param class-string<AbstractPaymentAdapter> $class       The adapter class.
	 * @param array<string,mixed>                  $credentials The credentials.
	 * @param array<string,mixed>                  $config      The provider config.
	 *
	 * @return AbstractPaymentAdapter
	 */
	private function adapter(string $class, array $credentials, array $config = []): AbstractPaymentAdapter {
		return new $class(
			credentials: $credentials,
			config: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			http: new StubHttpTransport(responses: [])
		);
	}//end adapter()

	/**
	 * The adapter classes under test.
	 *
	 * @return array<string,array{0:class-string<AbstractPaymentAdapter>}>
	 */
	public static function adapters(): array {
		return [
			'adyen' => [AdyenAdapter::class],
			'ccv' => [CcvAdapter::class],
			'mollie' => [MollieAdapter::class],
			'stripe' => [StripeAdapter::class],
		];
	}//end adapters()

	/**
	 * An empty payload or signature is refused before any secret is consulted.
	 *
	 * @param class-string<AbstractPaymentAdapter> $class The adapter class.
	 *
	 * @dataProvider adapters
	 *
	 * @return void
	 */
	public function testEmptyPayloadOrSignatureIsRefused(string $class): void {
		$adapter = $this->adapter(class: $class, credentials: ['webhookSecret' => 'secret']);

		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: '', signature: 'sig'));
		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: '{"a":1}', signature: ''));
	}//end testEmptyPayloadOrSignatureIsRefused()

	/**
	 * A missing or non-string webhook secret refuses every webhook.
	 *
	 * @param class-string<AbstractPaymentAdapter> $class The adapter class.
	 *
	 * @dataProvider adapters
	 *
	 * @return void
	 */
	public function testMissingOrNonStringSecretIsRefused(string $class): void {
		$this->assertFalse(
			condition: $this->adapter(class: $class, credentials: [])->validateWebhook(rawPayload: '{"a":1}', signature: 't=1,v1=ab')
		);
		$this->assertFalse(
			condition: $this->adapter(class: $class, credentials: ['webhookSecret' => 12345])->validateWebhook(rawPayload: '{"a":1}', signature: 't=1,v1=ab')
		);
	}//end testMissingOrNonStringSecretIsRefused()

	/**
	 * A Stripe signature without timestamp or v1 digest is refused.
	 *
	 * @return void
	 */
	public function testStripeSignatureNeedsTimestampAndDigest(): void {
		$adapter = $this->adapter(class: StripeAdapter::class, credentials: ['webhookSecret' => 'whsec']);
		$payload = '{"id":"evt_1"}';
		$digest  = hash_hmac(algo: 'sha256', data: time().'.'.$payload, key: 'whsec');

		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: $payload, signature: 'v1='.$digest));
		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: $payload, signature: 't='.time()));
	}//end testStripeSignatureNeedsTimestampAndDigest()

	/**
	 * A CCV signature uses the merchant id prefix only when it is a string.
	 *
	 * @return void
	 */
	public function testCcvIgnoresANonStringMerchantId(): void {
		$adapter = $this->adapter(class: CcvAdapter::class, credentials: ['webhookSecret' => 'ccv'], config: ['merchantId' => 42]);
		$payload = '{"reference":"R1"}';

		$unprefixed = hash_hmac(algo: 'sha512', data: $payload, key: 'ccv');
		$prefixed   = hash_hmac(algo: 'sha512', data: '42'.$payload, key: 'ccv');

		$this->assertTrue(condition: $adapter->validateWebhook(rawPayload: $payload, signature: $unprefixed));
		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: $payload, signature: $prefixed));
	}//end testCcvIgnoresANonStringMerchantId()

	/**
	 * A non-hex Adyen secret is used as literal key bytes.
	 *
	 * @return void
	 */
	public function testAdyenUsesANonHexSecretLiterally(): void {
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: ['webhookSecret' => 'not-hex-secret']);
		$payload = 'pspReference:merchant:100';

		$literal = base64_encode(hash_hmac(algo: 'sha256', data: $payload, key: 'not-hex-secret', binary: true));
		$this->assertTrue(condition: $adapter->validateWebhook(rawPayload: $payload, signature: $literal));
		$this->assertFalse(condition: $adapter->validateWebhook(rawPayload: $payload, signature: base64_encode(string: 'other')));
	}//end testAdyenUsesANonHexSecretLiterally()

	/**
	 * CCV webhooks map their status and fall back from reference to paymentId.
	 *
	 * @return void
	 */
	public function testCcvParsesWebhooks(): void {
		$adapter = $this->adapter(class: CcvAdapter::class, credentials: []);

		$this->assertSame(
			expected: ['sessionId' => 'CCV-1', 'status' => 'settled', 'eventId' => 'evt-9', 'error' => null],
			actual: $adapter->parseWebhook(payload: ['reference' => 'CCV-1', 'status' => 'SUCCESS', 'eventId' => 'evt-9'])
		);
		$this->assertSame(
			expected: ['sessionId' => 'P-2', 'status' => 'failed', 'eventId' => '', 'error' => null],
			actual: $adapter->parseWebhook(payload: ['paymentId' => 'P-2', 'status' => 'expired'])
		);
		$this->assertSame(
			expected: ['sessionId' => '', 'status' => 'pending', 'eventId' => '', 'error' => null],
			actual: $adapter->parseWebhook(payload: ['status' => 'unheard-of'])
		);
	}//end testCcvParsesWebhooks()

	/**
	 * An unsuccessful Adyen notification is failed whatever its event code.
	 *
	 * @return void
	 */
	public function testAdyenUnsuccessfulNotificationIsFailed(): void {
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: []);

		$this->assertSame(
			expected: ['sessionId' => 'PSP-1', 'status' => 'failed', 'eventId' => 'PSP-1', 'error' => null],
			actual: $adapter->parseWebhook(payload: ['pspReference' => 'PSP-1', 'eventCode' => 'CAPTURE', 'success' => 'false'])
		);
		$this->assertSame(
			expected: 'settled',
			actual: $adapter->parseWebhook(payload: ['pspReference' => 'PSP-1', 'eventCode' => 'capture', 'success' => 'true'])['status']
		);
	}//end testAdyenUnsuccessfulNotificationIsFailed()
}//end class
