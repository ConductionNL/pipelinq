<?php

/**
 * Unit tests for the webhook guards and status mapping of the payment adapters.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/StubHttpTransport.php';

/**
 * Webhook signature guards (empty payload, empty signature, missing or
 * malformed secret) and webhook status mapping of the four PSP adapters.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 */
#[CoversClass(AbstractPaymentAdapter::class)]
#[CoversClass(AdyenAdapter::class)]
#[CoversClass(CcvAdapter::class)]
#[CoversClass(MollieAdapter::class)]
#[CoversClass(StripeAdapter::class)]
class PaymentWebhookGuardsTest extends TestCase {
	/**
	 * Build an adapter of the given class with a stub transport.
	 *
	 * @param class-string<AbstractPaymentAdapter> $class The adapter class.
	 * @param array<string, mixed> $credentials The credentials.
	 * @param array<string, mixed> $config The provider config.
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
	 * Every adapter class.
	 *
	 * @return array<string, array{0: class-string<AbstractPaymentAdapter>}>
	 */
	public static function adapterClasses(): array {
		return [
			'adyen' => [AdyenAdapter::class],
			'ccv' => [CcvAdapter::class],
			'mollie' => [MollieAdapter::class],
			'stripe' => [StripeAdapter::class],
		];
	}//end adapterClasses()

	#[DataProvider('adapterClasses')]
	public function testAnEmptyPayloadOrSignatureIsRefused(string $class): void {
		$adapter = $this->adapter(class: $class, credentials: ['webhookSecret' => 'secret']);

		$this->assertFalse($adapter->validateWebhook(rawPayload: '', signature: 'sig'));
		$this->assertFalse($adapter->validateWebhook(rawPayload: '{}', signature: ''));
	}//end testAnEmptyPayloadOrSignatureIsRefused()

	#[DataProvider('adapterClasses')]
	public function testAMissingWebhookSecretRefusesEverySignature(string $class): void {
		$adapter = $this->adapter(class: $class, credentials: []);

		$this->assertFalse($adapter->validateWebhook(rawPayload: '{}', signature: 't=1,v1=abc'));
	}//end testAMissingWebhookSecretRefusesEverySignature()

	#[DataProvider('adapterClasses')]
	public function testANonStringWebhookSecretCountsAsMissing(string $class): void {
		$adapter = $this->adapter(class: $class, credentials: ['webhookSecret' => ['not', 'a', 'string']]);

		$this->assertFalse($adapter->validateWebhook(rawPayload: '{}', signature: 't=1,v1=abc'));
	}//end testANonStringWebhookSecretCountsAsMissing()

	public function testCcvSignsWithoutAMerchantIdWhenTheConfiguredOneIsNotAString(): void {
		$adapter = $this->adapter(
			class: CcvAdapter::class,
			credentials: ['webhookSecret' => 'ccv-secret'],
			config: ['merchantId' => 12345]
		);
		$payload = '{"reference":"CCV-1"}';

		$this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: hash_hmac('sha512', $payload, 'ccv-secret')));
		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: hash_hmac('sha512', '12345' . $payload, 'ccv-secret')));
	}//end testCcvSignsWithoutAMerchantIdWhenTheConfiguredOneIsNotAString()

	/**
	 * CCV provider statuses and the lifecycle status they map to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function ccvStatuses(): array {
		return [
			'success' => ['success', 'settled'],
			'manual intervention, any case' => ['ManualIntervention', 'pending'],
			'paid' => ['paid', 'settled'],
			'authorised' => ['AUTHORISED', 'captured'],
			'cancelled' => ['cancelled', 'failed'],
			'expired' => ['expired', 'failed'],
			'refunded' => ['refunded', 'refunded'],
			'unknown' => ['something-new', 'pending'],
			'empty' => ['', 'pending'],
		];
	}//end ccvStatuses()

	#[DataProvider('ccvStatuses')]
	public function testCcvParseWebhookMapsTheStatus(string $providerStatus, string $expected): void {
		$adapter = $this->adapter(class: CcvAdapter::class, credentials: []);

		$envelope = $adapter->parseWebhook(payload: ['reference' => 'CCV-9', 'status' => $providerStatus, 'eventId' => 'evt-9']);

		$this->assertSame(['sessionId' => 'CCV-9', 'status' => $expected, 'eventId' => 'evt-9', 'error' => null], $envelope);
	}//end testCcvParseWebhookMapsTheStatus()

	public function testCcvParseWebhookFallsBackToThePaymentIdAndReference(): void {
		$adapter = $this->adapter(class: CcvAdapter::class, credentials: []);

		$this->assertSame(
			['sessionId' => 'pay-1', 'status' => 'pending', 'eventId' => '', 'error' => null],
			$adapter->parseWebhook(payload: ['paymentId' => 'pay-1'])
		);
		$this->assertSame(
			['sessionId' => 'ref-1', 'status' => 'failed', 'eventId' => 'ref-1', 'error' => null],
			$adapter->parseWebhook(payload: ['reference' => 'ref-1', 'paymentId' => 'pay-1', 'status' => 'failed'])
		);
	}//end testCcvParseWebhookFallsBackToThePaymentIdAndReference()

	public function testAdyenUsesANonHexSecretAsLiteralKeyBytes(): void {
		$secret = 'not-hex-secret!';
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: ['webhookSecret' => $secret]);
		$payload = '{"pspReference":"psp_9"}';

		$this->assertTrue($adapter->validateWebhook(
			rawPayload: $payload,
			signature: base64_encode(hash_hmac('sha256', $payload, $secret, true))
		));
	}//end testAdyenUsesANonHexSecretAsLiteralKeyBytes()

	public function testAdyenUsesAnOddLengthHexLookalikeSecretAsLiteralKeyBytes(): void {
		$secret = 'abc';
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: ['webhookSecret' => $secret]);
		$payload = '{"pspReference":"psp_9"}';

		$this->assertTrue($adapter->validateWebhook(
			rawPayload: $payload,
			signature: base64_encode(hash_hmac('sha256', $payload, $secret, true))
		));
	}//end testAdyenUsesAnOddLengthHexLookalikeSecretAsLiteralKeyBytes()

	/**
	 * Adyen event codes with their success flag and the lifecycle status.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function adyenEvents(): array {
		return [
			'unsuccessful capture fails' => ['CAPTURE', 'false', 'failed'],
			'unsuccessful authorisation fails' => ['AUTHORISATION', 'false', 'failed'],
			'authorisation' => ['authorisation', 'true', 'captured'],
			'refund' => ['REFUND', 'true', 'refunded'],
			'cancel or refund' => ['CANCEL_OR_REFUND', 'true', 'refunded'],
			'cancellation' => ['CANCELLATION', 'true', 'failed'],
			'unknown event' => ['REPORT_AVAILABLE', 'true', 'pending'],
		];
	}//end adyenEvents()

	#[DataProvider('adyenEvents')]
	public function testAdyenParseWebhookMapsTheEvent(string $eventCode, string $success, string $expected): void {
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: []);

		$envelope = $adapter->parseWebhook(payload: ['pspReference' => 'psp_5', 'eventCode' => $eventCode, 'success' => $success]);

		$this->assertSame(['sessionId' => 'psp_5', 'status' => $expected, 'eventId' => 'psp_5', 'error' => null], $envelope);
	}//end testAdyenParseWebhookMapsTheEvent()

	public function testAdyenParseWebhookWithoutASuccessFlagFails(): void {
		$adapter = $this->adapter(class: AdyenAdapter::class, credentials: []);

		$this->assertSame('failed', $adapter->parseWebhook(payload: ['pspReference' => 'psp_6', 'eventCode' => 'CAPTURE'])['status']);
	}//end testAdyenParseWebhookWithoutASuccessFlagFails()

	/**
	 * Stripe-Signature headers that lack a timestamp or a v1 digest.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function incompleteStripeSignatures(): array {
		return [
			'no timestamp' => ['v1=abcdef'],
			'zero timestamp' => ['t=0,v1=abcdef'],
			'no v1 digest' => ['t=1700000000'],
			'empty v1 digest' => ['t=1700000000,v1='],
			'unrelated fields' => ['v0=abcdef'],
		];
	}//end incompleteStripeSignatures()

	#[DataProvider('incompleteStripeSignatures')]
	public function testStripeRefusesASignatureWithoutTimestampOrDigest(string $header): void {
		$adapter = $this->adapter(class: StripeAdapter::class, credentials: ['webhookSecret' => 'whsec_x']);

		$this->assertFalse($adapter->validateWebhook(rawPayload: '{"id":"evt_1"}', signature: $header));
	}//end testStripeRefusesASignatureWithoutTimestampOrDigest()

	public function testMollieAcceptsOnlyTheHexHmacOfTheBody(): void {
		$adapter = $this->adapter(class: MollieAdapter::class, credentials: ['webhookSecret' => 'mollie-secret']);
		$payload = 'id=tr_1';
		$expected = hash_hmac('sha256', $payload, 'mollie-secret');

		$this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: $expected));
		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload . ' ', signature: $expected));
	}//end testMollieAcceptsOnlyTheHexHmacOfTheBody()
}//end class
