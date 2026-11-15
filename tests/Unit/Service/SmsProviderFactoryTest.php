<?php

/**
 * Characterisation test for SmsProviderFactory::create.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use OCA\Pipelinq\Service\SmsProviderFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins which client each vendor gets and what the client is built with.
 */
class SmsProviderFactoryTest extends TestCase {
	/**
	 * Each vendor gets its own client with the row's number, secret, credentials and source.
	 *
	 * @return void
	 */
	public function testEachVendorGetsItsClient(): void {
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);
		$factory = new SmsProviderFactory($container, $logger);
		$row = ['phoneNumber' => '+3161', 'webhookSecret' => 'w', 'sourceId' => 'src-1', 'credentials' => ['k' => 'v']];

		foreach (['twilio' => TwilioSmsClient::class, 'messagebird' => MessageBirdSmsClient::class, 'cm-com' => CmComSmsClient::class] as $vendor => $class) {
			$client = $factory->create(['vendor' => $vendor] + $row);
			$this->assertInstanceOf($class, $client);
			$this->assertSame(
				['container' => $container, 'logger' => $logger, 'credentials' => ['k' => 'v'], 'fromNumber' => '+3161', 'webhookSecret' => 'w', 'sourceId' => 'src-1'],
				$this->state($client)
			);
		}
	}//end testEachVendorGetsItsClient()

	/**
	 * Fallbacks: the OpenConnector source id, an empty source as null, missing fields as empty; unknown vendors get nothing.
	 *
	 * @return void
	 */
	public function testFallbacksAndUnknownVendors(): void {
		$factory = new SmsProviderFactory($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$legacy = $this->state($factory->create(['vendor' => 'twilio', 'openconnectorSourceId' => 'oc-1']));
		$this->assertSame(['credentials' => [], 'fromNumber' => '', 'webhookSecret' => '', 'sourceId' => 'oc-1'], array_slice($legacy, 2));
		$this->assertNull($this->state($factory->create(['vendor' => 'cm-com', 'sourceId' => '', 'openconnectorSourceId' => 'oc-1']))['sourceId']);
		$this->assertNull($this->state($factory->create(['vendor' => 'messagebird']))['sourceId']);

		$this->assertNull($factory->create(['vendor' => 'Twilio']));
		$this->assertNull($factory->create(['vendor' => 'whatsapp']));
		$this->assertNull($factory->create([]));
	}//end testFallbacksAndUnknownVendors()

	/**
	 * The constructor-promoted properties of a client, in declaration order.
	 *
	 * @param object|null $client The client.
	 *
	 * @return array<string, mixed>
	 */
	private function state(?object $client): array {
		$this->assertNotNull($client);
		$state = [];
		foreach ((new \ReflectionClass($client))->getConstructor()->getParameters() as $parameter) {
			$property = new \ReflectionProperty($client, $parameter->getName());
			$state[$parameter->getName()] = $property->getValue($client);
		}

		return $state;
	}//end state()
}//end class
