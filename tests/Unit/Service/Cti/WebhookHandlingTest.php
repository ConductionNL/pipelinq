<?php

/**
 * Characterisation tests for CtiService::handleWebhook().
 *
 * Pins what the public CTI webhook does with a delivery: the body the
 * signature is checked against, that an unverified delivery is logged but
 * never dispatched, that a dispatch failure is logged and recorded, that an
 * unparseable payload is refused before anything is written, and the event
 * log row and answer of each case.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the CTI webhook handling.
 */
class WebhookHandlingTest extends TestCase {

	/**
	 * The signature checks, as [body, signature].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $checks = [];

	/**
	 * The event log rows written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $eventLog = [];

	/**
	 * Ticket lookups made by a dispatch.
	 *
	 * @var integer
	 */
	private int $lookups = 0;

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * A verified delivery with a raw body is checked against that body,
	 * dispatched and logged.
	 *
	 * @return void
	 */
	public function testAVerifiedDeliveryIsCheckedAgainstTheRawBodyAndLogged(): void {
		$service = $this->service(accepts: true, event: $this->event(type: 'unknown'));

		$result = $service->handleWebhook('callvoip', ['a' => 1], '{"raw":true}', 'sig-1');

		$this->assertSame(['logged' => true, 'valid' => true, 'interactionId' => null], $result);
		$this->assertSame([['{"raw":true}', 'sig-1']], $this->checks);
		$this->assertSame(
			[['platform' => 'callvoip', 'event_type' => 'unknown', 'external_call_id' => 'call-1', 'payload_json' => ['k' => 'v'], 'processing_error' => null, 'signature_valid' => true]],
			$this->eventLogWithoutTimes()
		);
	}//end testAVerifiedDeliveryIsCheckedAgainstTheRawBodyAndLogged()

	/**
	 * Without a raw body the encoded payload is checked; without a
	 * signature an empty one is, so it fails closed in the adapter.
	 *
	 * @return void
	 */
	public function testWithoutARawBodyOrSignatureTheEncodedPayloadAndAnEmptySignatureAreChecked(): void {
		$service = $this->service(accepts: true, event: $this->event(type: 'unknown'));

		$service->handleWebhook('callvoip', ['b' => 'x']);
		$service->handleWebhook('callvoip', ["\xB1"]);

		$this->assertSame([['{"b":"x"}', ''], ['', '']], $this->checks);
	}//end testWithoutARawBodyOrSignatureTheEncodedPayloadAndAnEmptySignatureAreChecked()

	/**
	 * An unverified delivery is logged as such and never dispatched.
	 *
	 * @return void
	 */
	public function testAnUnverifiedDeliveryIsLoggedAndNotDispatched(): void {
		$service = $this->service(accepts: false, event: $this->event(type: 'ringing'));

		$result = $service->handleWebhook('callvoip', ['a' => 1], '{}', 'bad');

		$this->assertSame(['logged' => true, 'valid' => false, 'interactionId' => null], $result);
		$this->assertSame(0, $this->lookups);
		$this->assertSame('Invalid signature', $this->eventLogWithoutTimes()[0]['processing_error']);
		$this->assertFalse($this->eventLogWithoutTimes()[0]['signature_valid']);
	}//end testAnUnverifiedDeliveryIsLoggedAndNotDispatched()

	/**
	 * A dispatch that fails is logged as an error and recorded on the event.
	 *
	 * @return void
	 */
	public function testAFailingDispatchIsLoggedAndRecorded(): void {
		$service = $this->service(accepts: true, event: $this->event(type: 'ringing'));

		$result = $service->handleWebhook('asterisk', ['a' => 1], '{}', 'ok');

		$this->assertSame(['logged' => true, 'valid' => true, 'interactionId' => null], $result);
		$this->assertSame(1, $this->lookups);
		$this->assertSame([['error', 'CTI webhook dispatch failed', ['exception' => 'ticket store down', 'platform' => 'asterisk']]], $this->logs);
		$this->assertSame('ticket store down', $this->eventLogWithoutTimes()[0]['processing_error']);
		$this->assertTrue($this->eventLogWithoutTimes()[0]['signature_valid']);
	}//end testAFailingDispatchIsLoggedAndRecorded()

	/**
	 * An unparseable payload is refused after the signature check and
	 * before anything is dispatched or logged to the event log.
	 *
	 * @return void
	 */
	public function testAnUnparseablePayloadIsRefused(): void {
		$service = $this->service(accepts: true, event: null);

		try {
			$service->handleWebhook('ringcentral', ['a' => 1], '{}', 'ok');
			$this->fail('an unparseable payload must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The webhook payload could not be parsed.', $e->getMessage());
			$this->assertSame('bad json', $e->getPrevious()?->getMessage());
		}

		$this->assertCount(1, $this->checks);
		$this->assertSame([], $this->eventLog);
		$this->assertSame(0, $this->lookups);
		$this->assertSame([['warning', 'CTI webhook: adapter could not parse the payload', ['platform' => 'ringcentral', 'error' => 'bad json']]], $this->logs);
	}//end testAnUnparseablePayloadIsRefused()

	/**
	 * The event log rows without their clock fields, which must be set.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function eventLogWithoutTimes(): array {
		return array_map(
			function (array $row): array {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['received_at']);
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['processed_at']);
				unset($row['received_at'], $row['processed_at']);
				return $row;
			},
			$this->eventLog
		);
	}//end eventLogWithoutTimes()

	/**
	 * A normalised event.
	 *
	 * @param string $type The event type.
	 *
	 * @return CtiWebhookResult
	 */
	private function event(string $type): CtiWebhookResult {
		return new CtiWebhookResult(eventType: $type, externalCallId: 'call-1', raw: ['k' => 'v']);
	}//end event()

	/**
	 * A real CtiService over recording doubles.
	 *
	 * @param bool                  $accepts Whether the adapter accepts the signature.
	 * @param CtiWebhookResult|null $event   The parsed event, or null when parsing throws.
	 *
	 * @return CtiService
	 */
	private function service(bool $accepts, ?CtiWebhookResult $event): CtiService {
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('verifyWebhookSignature')->willReturnCallback(
			function (string $body, string $signature) use ($accepts): bool {
				$this->checks[] = [$body, $signature];
				return $accepts;
			}
		);
		$adapter->method('handleInboundWebhook')->willReturnCallback(
			static function () use ($event): CtiWebhookResult {
				if ($event === null) {
					throw new RuntimeException('bad json');
				}

				return $event;
			}
		);
		$registry = $this->createMock(AdapterRegistry::class);
		$registry->method('get')->willReturn($adapter);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg-1', 'ctiEventLog_schema' => 'log-1'][$key] ?? $default)
		);

		$eventLog = new class {
			/**
			 * Rows written.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object The row.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object): array {
				$this->rows[] = $object;
				return $object;
			}
		};
		$this->eventLog = &$eventLog->rows;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($eventLog);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('findByType')->willReturnCallback(
			function (): array {
				$this->lookups++;
				throw new RuntimeException('ticket store down');
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new CtiService(
			$container,
			$appConfig,
			$registry,
			new PhoneNormaliser($appConfig, $this->createMock(LoggerInterface::class)),
			$this->createMock(CtiContactMatcher::class),
			$this->createMock(CtiDispositionService::class),
			$tickets,
			$logger,
		);
	}//end service()
}//end class
