<?php

/**
 * Unit tests for WebhookProcessorJob: queued inbound webhooks are handed to their channel adapter and the outcome is written back on the queue row.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\LabelRenameJob;
use OCA\Pipelinq\BackgroundJob\WebhookProcessorJob;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for WebhookProcessorJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\WebhookProcessorJob
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class WebhookProcessorJobTest extends TestCase {

	/**
	 * A fake ObjectService answering the queue and recording saves.
	 *
	 * @param mixed      $rows      What findAll answers, or a Throwable.
	 * @param bool       $saveFails Whether saveObject throws.
	 *
	 * @return object
	 */
	private static function objects(mixed $rows, bool $saveFails = false): object {
		return new class ($rows, $saveFails) {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $rows      The rows.
			 * @param bool  $saveFails Whether saves fail.
			 */
			public function __construct(private mixed $rows, private bool $saveFails) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->queries[] = $config;
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end findAll()

			/**
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The row id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($this->saveFails === true) {
					throw new RuntimeException('save refused');
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}//end saveObject()
		};
	}//end objects()

	/**
	 * Run the job once.
	 *
	 * @param object|\Throwable     $objects  The ObjectService, or what resolving it throws.
	 * @param array<string, string> $config   App config strings.
	 * @param WhatsAppAdapter|null  $whatsApp The WhatsApp adapter.
	 * @param SmsAdapter|null       $sms      The SMS adapter.
	 * @param LoggerInterface|null  $logger   The logger.
	 *
	 * @return void
	 */
	private function runJob(object $objects, array $config = [], ?WhatsAppAdapter $whatsApp = null, ?SmsAdapter $sms = null, ?LoggerInterface $logger = null): void {
		$container = $this->createMock(ContainerInterface::class);
		if ($objects instanceof \Throwable) {
			$container->method('get')->willThrowException($objects);
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default));

		$job = new WebhookProcessorJob(
			$this->createMock(ITimeFactory::class),
			$container,
			$appConfig,
			($whatsApp ?? $this->createMock(WhatsAppAdapter::class)),
			($sms ?? $this->createMock(SmsAdapter::class)),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
		(new ReflectionMethod(WebhookProcessorJob::class, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Each queued row goes to its channel's adapter and is saved back with the outcome, under its own id.
	 *
	 * @return void
	 */
	public function testQueuedRowsAreDispatchedAndSavedBack(): void {
		$objects = self::objects(
			[
				['uuid' => 'w-1', 'channel' => 'whatsapp', 'rawBody' => '{"a":1}', 'signature' => 'sig-1', 'providerId' => 'meta'],
				['@self' => ['id' => 'w-2'], 'channel' => 'sms', 'rawBody' => 'b', 'signature' => 'sig-2', 'providerId' => 'twilio'],
				['channel' => 'fax'],
			]
		);
		$whatsApp = $this->createMock(WhatsAppAdapter::class);
		$whatsApp->expects($this->once())->method('handleInboundWebhook')->with('{"a":1}', 'sig-1', 'meta')->willReturn(['status' => 'processed', 'messages' => 1]);
		$sms = $this->createMock(SmsAdapter::class);
		$sms->expects($this->once())->method('handleInboundWebhook')->with('b', 'sig-2', 'twilio')->willReturn(['status' => 'rejected']);

		$this->runJob($objects, ['register' => 'reg', 'webhookQueue_schema' => 'queue'], $whatsApp, $sms);

		$this->assertSame([['filters' => ['status' => 'queued', 'register' => 'reg', 'schema' => 'queue']]], $objects->queries);
		$this->assertSame(['w-1', 'w-2', null], array_column($objects->saves, 'uuid'));
		$this->assertSame(['processed', 'rejected', 'unknownChannel'], array_map(static fn (array $s): string => $s['object']['status'], $objects->saves));
		$this->assertSame(['status' => 'processed', 'messages' => 1], $objects->saves[0]['object']['result']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $objects->saves[0]['object']['processedAt']);
		$this->assertSame(['reg', 'queue'], [$objects->saves[0]['register'], $objects->saves[0]['schema']]);
	}//end testQueuedRowsAreDispatchedAndSavedBack()

	/**
	 * Without configured slugs the default register and queue schema are read.
	 *
	 * @return void
	 */
	public function testTheDefaultQueueIsReadWithoutConfiguration(): void {
		$objects = self::objects([]);
		$this->runJob($objects);
		$this->assertSame([['filters' => ['status' => 'queued', 'register' => 'pipelinq', 'schema' => 'webhookQueue']]], $objects->queries);
		$this->assertSame([], $objects->saves);
	}//end testTheDefaultQueueIsReadWithoutConfiguration()

	/**
	 * An adapter that throws marks the row processingFailed with the reason.
	 *
	 * @return void
	 */
	public function testAFailingAdapterMarksTheRowFailed(): void {
		$objects = self::objects([['id' => 'w-9', 'channel' => 'sms', 'rawBody' => 'x']]);
		$sms = $this->createMock(SmsAdapter::class);
		$sms->method('handleInboundWebhook')->willThrowException(new RuntimeException('bad signature'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('WebhookProcessorJob.drain: processing failed', ['id' => 'w-9', 'exception' => 'bad signature']);

		$this->runJob($objects, [], null, $sms, $logger);
		$this->assertSame(['status' => 'processingFailed', 'error' => 'bad signature'], $objects->saves[0]['object']['result']);
		$this->assertSame('processingFailed', $objects->saves[0]['object']['status']);
	}//end testAFailingAdapterMarksTheRowFailed()

	/**
	 * A failed save, a failed read and a missing OpenRegister are each logged.
	 *
	 * @return void
	 */
	public function testStoreFailuresAreLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('WebhookProcessorJob.drain: save failed', ['id' => 'w-1', 'exception' => 'save refused']);
		$this->runJob(self::objects([['slug' => 'w-1', 'channel' => 'fax']], true), [], null, null, $logger);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('WebhookProcessorJob.drain: findAll failed', ['exception' => 'db down']);
		$this->runJob(self::objects(new RuntimeException('db down')), [], null, null, $logger);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('WebhookProcessorJob.getObjectService: OpenRegister unavailable', ['exception' => 'not installed']);
		$this->runJob(new RuntimeException('not installed'), [], null, null, $logger);
	}//end testStoreFailuresAreLogged()

	/**
	 * A non-list answer from the queue processes nothing.
	 *
	 * @return void
	 */
	public function testANonListAnswerProcessesNothing(): void {
		$objects = self::objects('nothing');
		$sms = $this->createMock(SmsAdapter::class);
		$sms->expects($this->never())->method('handleInboundWebhook');
		$this->runJob($objects, [], null, $sms);
		$this->assertSame([], $objects->saves);
	}//end testANonListAnswerProcessesNothing()
}//end class
