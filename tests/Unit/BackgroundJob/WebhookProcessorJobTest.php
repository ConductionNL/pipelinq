<?php

/**
 * Unit tests for WebhookProcessorJob: draining the queued inbound webhooks per channel and saving each outcome.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\WebhookProcessorJob;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The webhook queue drain.
 */
class WebhookProcessorJobTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var WhatsAppAdapter&MockObject
	 */
	private WhatsAppAdapter&MockObject $whatsApp;

	/**
	 * @var SmsAdapter&MockObject
	 */
	private SmsAdapter&MockObject $sms;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Configured register and schema slugs.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->whatsApp  = $this->createMock(WhatsAppAdapter::class);
		$this->sms       = $this->createMock(SmsAdapter::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->config    = [];
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
	}//end setUp()

	/**
	 * A recording object service holding the queued rows.
	 *
	 * @param array<int, mixed> $rows     What findAll answers.
	 * @param \Throwable|null   $findFail Thrown by findAll when set.
	 * @param \Throwable|null   $saveFail Thrown by saveObject when set.
	 *
	 * @return object
	 */
	private function objectService(array $rows, ?\Throwable $findFail = null, ?\Throwable $saveFail = null): object {
		$service = new class {
			/** @var array<int, mixed> */
			public array $rows = [];

			/** @var array<int, array<string, mixed>> */
			public array $findConfigs = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			public ?\Throwable $findFail = null;

			public ?\Throwable $saveFail = null;

			/**
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->findConfigs[] = $config;
				if ($this->findFail !== null) {
					throw $this->findFail;
				}

				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->saveFail !== null) {
					throw $this->saveFail;
				}

				return $object;
			}
		};
		$service->rows     = $rows;
		$service->findFail = $findFail;
		$service->saveFail = $saveFail;
		$this->container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);
		return $service;
	}//end objectService()

	/**
	 * Run one drain.
	 *
	 * @return void
	 */
	private function drain(): void {
		$job = new WebhookProcessorJob(
			$this->createMock(ITimeFactory::class),
			$this->container,
			$this->appConfig,
			$this->whatsApp,
			$this->sms,
			$this->logger
		);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end drain()

	/**
	 * Queued rows are read with the default slugs and each is routed to its channel adapter.
	 *
	 * @return void
	 */
	public function testRoutesEachRowToItsChannelAndSavesTheOutcome(): void {
		$service = $this->objectService([
			['uuid' => 'w-1', 'channel' => 'whatsapp', 'rawBody' => '{"a":1}', 'signature' => 'sig-1', 'providerId' => 'meta'],
			['@self' => ['id' => 's-1'], 'channel' => 'sms', 'rawBody' => 'Body=hi', 'signature' => 'sig-2', 'providerId' => 'twilio'],
		]);
		$this->whatsApp->expects($this->once())->method('handleInboundWebhook')
			->with('{"a":1}', 'sig-1', 'meta')
			->willReturn(['status' => 'processed', 'messages' => 1]);
		$this->sms->expects($this->once())->method('handleInboundWebhook')
			->with('Body=hi', 'sig-2', 'twilio')
			->willReturn(['status' => 'rejected']);

		$this->drain();

		$this->assertSame(
			[['filters' => ['status' => 'queued', 'register' => 'pipelinq', 'schema' => 'webhookQueue']]],
			$service->findConfigs
		);
		$this->assertCount(2, $service->saved);
		$first = $service->saved[0];
		$this->assertSame('w-1', $first['uuid']);
		$this->assertSame('pipelinq', $first['register']);
		$this->assertSame('webhookQueue', $first['schema']);
		$this->assertSame('processed', $first['object']['status']);
		$this->assertSame(['status' => 'processed', 'messages' => 1], $first['object']['result']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['object']['processedAt']);
		$this->assertSame('s-1', $service->saved[1]['uuid']);
		$this->assertSame('rejected', $service->saved[1]['object']['status']);
	}//end testRoutesEachRowToItsChannelAndSavesTheOutcome()

	/**
	 * The configured register and queue schema replace the defaults for read and save.
	 *
	 * @return void
	 */
	public function testUsesTheConfiguredSlugs(): void {
		$this->config = ['register' => 'crm', 'webhookQueue_schema' => 'inbound'];
		$service      = $this->objectService([['id' => 'x', 'channel' => 'email']]);

		$this->drain();

		$this->assertSame('crm', $service->findConfigs[0]['filters']['register']);
		$this->assertSame('inbound', $service->findConfigs[0]['filters']['schema']);
		$this->assertSame('crm', $service->saved[0]['register']);
		$this->assertSame('inbound', $service->saved[0]['schema']);
	}//end testUsesTheConfiguredSlugs()

	/**
	 * An unknown channel is saved as unknownChannel without calling an adapter,
	 * and a row without an id is saved without a uuid.
	 *
	 * @return void
	 */
	public function testAnUnknownChannelIsMarkedAndARowWithoutIdIsSavedNew(): void {
		$service = $this->objectService([['channel' => 'fax', 'id' => '']]);
		$this->whatsApp->expects($this->never())->method('handleInboundWebhook');
		$this->sms->expects($this->never())->method('handleInboundWebhook');

		$this->drain();

		$this->assertNull($service->saved[0]['uuid']);
		$this->assertSame('unknownChannel', $service->saved[0]['object']['status']);
		$this->assertSame(['status' => 'unknownChannel'], $service->saved[0]['object']['result']);
	}//end testAnUnknownChannelIsMarkedAndARowWithoutIdIsSavedNew()

	/**
	 * A throwing adapter marks the row processingFailed with the error, and the next row still runs.
	 *
	 * @return void
	 */
	public function testAThrowingAdapterMarksTheRowFailed(): void {
		$service = $this->objectService([
			['uuid' => 'w-1', 'channel' => 'whatsapp'],
			['uuid' => 's-1', 'channel' => 'sms'],
		]);
		$this->whatsApp->method('handleInboundWebhook')->willThrowException(new \RuntimeException('bad signature'));
		$this->sms->method('handleInboundWebhook')->willReturn(['status' => 'processed']);
		$this->logger->expects($this->once())->method('warning')
			->with('WebhookProcessorJob.drain: processing failed', ['id' => 'w-1', 'exception' => 'bad signature']);

		$this->drain();

		$this->assertSame('processingFailed', $service->saved[0]['object']['status']);
		$this->assertSame(['status' => 'processingFailed', 'error' => 'bad signature'], $service->saved[0]['object']['result']);
		$this->assertSame('processed', $service->saved[1]['object']['status']);
	}//end testAThrowingAdapterMarksTheRowFailed()

	/**
	 * A failing save is logged per row and does not stop the drain.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$service = $this->objectService(
			[['uuid' => 'a', 'channel' => 'x'], ['uuid' => 'b', 'channel' => 'x']],
			null,
			new \RuntimeException('locked')
		);
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('WebhookProcessorJob.drain: save failed', $this->callback(static fn (array $c): bool => $c['exception'] === 'locked'));

		$this->drain();

		$this->assertCount(2, $service->saved);
	}//end testAFailingSaveIsLogged()

	/**
	 * A failing queue read is logged and nothing is processed.
	 *
	 * @return void
	 */
	public function testAFailingQueueReadIsLogged(): void {
		$service = $this->objectService([], new \RuntimeException('no table'));
		$this->logger->expects($this->once())->method('warning')
			->with('WebhookProcessorJob.drain: findAll failed', ['exception' => 'no table']);

		$this->drain();

		$this->assertSame([], $service->saved);
	}//end testAFailingQueueReadIsLogged()

	/**
	 * Without OpenRegister the drain logs and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingRuns(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$this->whatsApp->expects($this->never())->method('handleInboundWebhook');
		$this->logger->expects($this->once())->method('warning')
			->with('WebhookProcessorJob.getObjectService: OpenRegister unavailable', ['exception' => 'not installed']);
		$this->logger->expects($this->never())->method('error');

		$this->drain();
	}//end testWithoutOpenRegisterNothingRuns()
}//end class
