<?php

/**
 * Golden test for the SMS vendor clients' webhook signature check.
 *
 * Pins, for every vendor client, which signatures are accepted and which are
 * refused: an unset secret or an empty header always refuses, the hex
 * HMAC-SHA256 of the raw body is accepted, Twilio also accepts the base64
 * form, and anything else is refused.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Provider;

use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Accepted and refused signatures per vendor.
 */
class SmsClientSignatureGoldenTest extends TestCase {
	private const BODY = '{"status":"delivered","id":"m-1"}';
	private const SECRET = 's3cret';

	/**
	 * Every vendor accepts exactly the signatures it accepted before.
	 *
	 * @return void
	 */
	public function testAcceptedAndRefusedSignatures(): void {
		$hex = hash_hmac('sha256', self::BODY, self::SECRET);
		$base64 = base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true));
		$expected = [
			CmComSmsClient::class => ['vendor' => 'cm-com', 'hex' => true, 'base64' => false],
			MessageBirdSmsClient::class => ['vendor' => 'messagebird', 'hex' => true, 'base64' => false],
			TwilioSmsClient::class => ['vendor' => 'twilio', 'hex' => true, 'base64' => true],
		];
		foreach ($expected as $class => $answers) {
			$client = $this->client(class: $class, secret: self::SECRET);
			self::assertSame($answers['vendor'], $client->getVendor(), $class);
			self::assertSame($answers['hex'], $client->verifySignature(self::BODY, $hex), $class . ' hex');
			self::assertSame($answers['base64'], $client->verifySignature(self::BODY, $base64), $class . ' base64');
			self::assertFalse($client->verifySignature(self::BODY, strtoupper($hex)), $class . ' upper hex');
			self::assertFalse($client->verifySignature(self::BODY . ' ', $hex), $class . ' changed body');
			self::assertFalse($client->verifySignature(self::BODY, ''), $class . ' empty header');
			self::assertFalse($client->verifySignature(self::BODY, 'nope'), $class . ' garbage');
			self::assertFalse($this->client(class: $class, secret: '')->verifySignature(self::BODY, hash_hmac('sha256', self::BODY, '')), $class . ' unset secret');
		}
	}//end testAcceptedAndRefusedSignatures()

	/**
	 * Build a vendor client.
	 *
	 * @param class-string<SmsProviderClientInterface> $class  The client class.
	 * @param string                                   $secret The webhook secret.
	 *
	 * @return SmsProviderClientInterface
	 */
	private function client(string $class, string $secret): SmsProviderClientInterface {
		return new $class(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: $secret,
			sourceId: 'src-1',
		);
	}//end client()
}//end class
