<?php

/**
 * Characterisation tests for the InitializeSettings repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-auto-configuration-on-install-repair-step
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use ArrayObject;
use OCA\Pipelinq\Repair\InitializeSettings;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The output, log and service calls of every path of the repair step.
 */
class InitializeSettingsTest extends TestCase {
	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var ArrayObject<int, array<int, mixed>>
	 */
	private ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
	}//end setUp()

	/**
	 * Without OpenRegister the step warns, logs and finishes early.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegister(): void {
		$this->step(installed: ['files'], failing: [])->run($this->recordingOutput());
		$this->assertSame(
			[
				['start', 5],
				['warning', 'OpenRegister app is not installed -- skipping configuration import'],
				['log:warning', 'Pipelinq: OpenRegister not available, skipping register initialization', []],
				['advance', 3],
				['finish'],
			],
			$this->calls->getArrayCopy()
		);
	}//end testWithoutOpenRegister()

	/**
	 * Every step succeeds, in order.
	 *
	 * @return void
	 */
	public function testEveryStepSucceeds(): void {
		$this->step(installed: ['openregister'], failing: [])->run($this->recordingOutput());
		$this->assertSame(
			[
				['start', 5],
				['info', 'Loading Pipelinq configuration...'],
				['advance', 1],
				['loadSettings', false],
				['info', 'Configuration loaded: 2 registers, 3 schemas'],
				['log:info', 'Pipelinq: Register and schemas initialized successfully', []],
				['advance', 1],
				['info', 'Checking default pipelines...'],
				['createDefaultPipelines'],
				['info', 'Default pipelines checked/created'],
				['advance', 1],
				['info', 'Checking default lead sources and request channels...'],
				['ensureDefaults', 'pipelinq_lead_source', ['website', 'email', 'phone', 'referral', 'partner', 'campaign', 'social_media', 'event', 'tender', 'other']],
				['ensureDefaults', 'pipelinq_request_channel', ['phone', 'email', 'website', 'counter', 'post']],
				['info', 'Default lead sources and request channels checked/created'],
				['advance', 1],
				['info', 'Checking default skills...'],
				['createDefaultSkills'],
				['info', 'Default skills checked/created'],
				['advance', 1],
				['finish'],
			],
			$this->calls->getArrayCopy()
		);
	}//end testEveryStepSucceeds()

	/**
	 * Each failing step warns and logs, and the next step still runs.
	 *
	 * @return void
	 */
	public function testEachFailureIsReportedAndTheRunContinues(): void {
		$this->step(installed: ['openregister'], failing: ['loadSettings', 'createDefaultPipelines', 'ensureDefaults', 'createDefaultSkills'])->run($this->recordingOutput());
		$this->assertSame(
			[
				['start', 5],
				['info', 'Loading Pipelinq configuration...'],
				['advance', 1],
				['loadSettings', false],
				['warning', 'Failed to load configuration: loadSettings failed'],
				['log:error', 'Pipelinq initialization failed', ['exception' => 'loadSettings failed']],
				['advance', 1],
				['info', 'Checking default pipelines...'],
				['createDefaultPipelines'],
				['warning', 'Failed to create default pipelines: createDefaultPipelines failed'],
				['log:error', 'Pipelinq default pipeline creation failed', ['exception' => 'createDefaultPipelines failed']],
				['advance', 1],
				['info', 'Checking default lead sources and request channels...'],
				['ensureDefaults', 'pipelinq_lead_source', ['website', 'email', 'phone', 'referral', 'partner', 'campaign', 'social_media', 'event', 'tender', 'other']],
				['warning', 'Failed to create default tags: ensureDefaults failed'],
				['log:error', 'Pipelinq default tag creation failed', ['exception' => 'ensureDefaults failed']],
				['advance', 1],
				['info', 'Checking default skills...'],
				['createDefaultSkills'],
				['warning', 'Failed to create default skills: createDefaultSkills failed'],
				['log:error', 'Pipelinq default skill creation failed', ['exception' => 'createDefaultSkills failed']],
				['advance', 1],
				['finish'],
			],
			$this->calls->getArrayCopy()
		);
	}//end testEachFailureIsReportedAndTheRunContinues()

	/**
	 * A recording output.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$calls = $this->calls;
		$output = $this->createMock(IOutput::class);
		$output->method('startProgress')->willReturnCallback(static function (int $max = 0) use ($calls): void {
			$calls[] = ['start', $max];
		});
		$output->method('advance')->willReturnCallback(static function (int $step = 1, string $description = '') use ($calls): void {
			$calls[] = ['advance', $step];
		});
		$output->method('finishProgress')->willReturnCallback(static function () use ($calls): void {
			$calls[] = ['finish'];
		});
		$output->method('info')->willReturnCallback(static function (string $message) use ($calls): void {
			$calls[] = ['info', $message];
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use ($calls): void {
			$calls[] = ['warning', $message];
		});
		return $output;
	}//end recordingOutput()

	/**
	 * The repair step over recording doubles.
	 *
	 * @param array<int, string> $installed The installed apps.
	 * @param array<int, string> $failing   The service calls that throw.
	 *
	 * @return InitializeSettings
	 */
	private function step(array $installed, array $failing): InitializeSettings {
		$calls = $this->calls;
		$fail = static function (string $call) use ($failing): void {
			if (in_array($call, $failing, true) === true) {
				throw new RuntimeException($call . ' failed');
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('loadSettings')->willReturnCallback(static function (bool $force = false) use ($calls, $fail): array {
			$calls[] = ['loadSettings', $force];
			$fail('loadSettings');
			return ['registers' => ['r1', 'r2'], 'schemas' => ['s1', 's2', 's3']];
		});
		$settings->method('createDefaultPipelines')->willReturnCallback(static function () use ($calls, $fail) {
			$calls[] = ['createDefaultPipelines'];
			$fail('createDefaultPipelines');
			return [];
		});
		$settings->method('createDefaultSkills')->willReturnCallback(static function () use ($calls, $fail) {
			$calls[] = ['createDefaultSkills'];
			$fail('createDefaultSkills');
			return [];
		});
		$tags = $this->createMock(SystemTagService::class);
		$tags->method('ensureDefaults')->willReturnCallback(static function (string $objectType, array $defaults) use ($calls, $fail) {
			$calls[] = ['ensureDefaults', $objectType, $defaults];
			$fail('ensureDefaults');
			return [];
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $id === SystemTagService::class ? $tags : $settings);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(static function (string $message, array $context = []) use ($calls, $level): void {
				$calls[] = ['log:' . $level, $message, $context];
			});
		}

		return new InitializeSettings($apps, $container, $logger);
	}//end step()
}//end class
