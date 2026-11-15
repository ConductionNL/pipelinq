<?php

/**
 * One language field per party, and industry as a list.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\NormalisePartyFormFields;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for NormalisePartyFormFields.
 *
 * @covers \OCA\Pipelinq\Repair\NormalisePartyFormFields
 */
class NormalisePartyFormFieldsTest extends TestCase {
	/**
	 * A stated language moves into correspondenceLanguage.
	 *
	 * @return void
	 */
	public function testAStatedLanguageIsCopied(): void {
		$this->assertSame(['correspondenceLanguage' => 'nl'], NormalisePartyFormFields::patchFor(['language' => 'NL']));
	}//end testAStatedLanguageIsCopied()

	/**
	 * A correspondence language already set is never overwritten.
	 *
	 * @return void
	 */
	public function testASetCorrespondenceLanguageWins(): void {
		$this->assertSame([], NormalisePartyFormFields::patchFor(['language' => 'nl', 'correspondenceLanguage' => 'en']));
	}//end testASetCorrespondenceLanguageWins()

	/**
	 * A string industry is wrapped; an empty one becomes an empty list.
	 *
	 * @return void
	 */
	public function testAnIndustryStringBecomesAList(): void {
		$this->assertSame(['industry' => ['Retail']], NormalisePartyFormFields::patchFor(['industry' => 'Retail']));
		$this->assertSame(['industry' => []], NormalisePartyFormFields::patchFor(['industry' => '']));
	}//end testAnIndustryStringBecomesAList()

	/**
	 * A list stays a list, and a clean party needs nothing.
	 *
	 * @return void
	 */
	public function testANormalisedPartyNeedsNothing(): void {
		$this->assertSame([], NormalisePartyFormFields::patchFor(['industry' => ['Retail'], 'correspondenceLanguage' => 'nl']));
	}//end testANormalisedPartyNeedsNothing()

	/**
	 * The step saves exactly the patched parties, merged over what is stored.
	 *
	 * @return void
	 */
	public function testTheRunSavesOnlyPartiesThatNeedIt(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '7', 'client_schema' => '11', 'contact_schema' => ''][$key] ?? $default
		);

		$objectService = new class {
			/**
			 * Saved payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Stored rows.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(mixed ...$args): array {
				return [
					['id' => 'a', 'name' => 'Bakkerij', 'industry' => 'Retail'],
					['id' => 'b', 'name' => 'Gemeente', 'industry' => ['Public sector']],
				];
			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param array<string,mixed> $object The payload.
			 * @param mixed ...$rest Ignored.
			 *
			 * @return void
			 */
			public function saveObject(array $object, mixed ...$rest): void {
				$this->saved[] = $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$step = new NormalisePartyFormFields($appConfig, $container, $this->createMock(IGroupManager::class), new NullLogger());
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([['id' => 'a', 'name' => 'Bakkerij', 'industry' => ['Retail']]], $objectService->saved);
	}//end testTheRunSavesOnlyPartiesThatNeedIt()

	/**
	 * Run the step over a fake ObjectService.
	 *
	 * @param array<string, string>   $config   App config values.
	 * @param array<int, mixed>|null  $rows     What findAll() returns, or null when it throws.
	 * @param array<int, string>      $failIds  Ids whose save throws.
	 * @param LoggerInterface|null    $logger   The logger.
	 * @param IOutput|null            $output   The output.
	 *
	 * @return object The fake ObjectService, with its saves.
	 */
	private function runOver(array $config, ?array $rows, array $failIds = [], ?LoggerInterface $logger = null, ?IOutput $output = null): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default
		);

		$objectService = new class ($rows, $failIds) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * @param array<int, mixed>|null $rows    The rows, or null to fail.
			 * @param array<int, string>     $failIds Ids whose save throws.
			 */
			public function __construct(private ?array $rows, private array $failIds) {
			}

			/**
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(mixed ...$args): array {
				if ($this->rows === null) {
					throw new RuntimeException('index offline');
				}

				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object The payload.
			 * @param mixed                ...$rest Ignored.
			 *
			 * @return void
			 */
			public function saveObject(array $object, mixed ...$rest): void {
				if (in_array($object['id'], $this->failIds, true) === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		(new NormalisePartyFormFields($appConfig, $container, $this->createMock(IGroupManager::class), $logger ?? new NullLogger()))
			->run($output ?? $this->createMock(IOutput::class));

		return $objectService;
	}//end runOver()

	/**
	 * Without a configured register the step says so and reads nothing.
	 *
	 * @return void
	 */
	public function testWithoutARegisterTheStepSkips(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('NormalisePartyFormFields: pipelinq register not configured, skipping');

		(new NormalisePartyFormFields($appConfig, $container, $this->createMock(IGroupManager::class), new NullLogger()))->run($output);
	}//end testWithoutARegisterTheStepSkips()

	/**
	 * Without OpenRegister the step warns and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepWarns(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('7');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('NormalisePartyFormFields: OpenRegister ObjectService unavailable, skipping (not installed)');
		$output->expects($this->never())->method('info');

		(new NormalisePartyFormFields($appConfig, $container, $this->createMock(IGroupManager::class), new NullLogger()))->run($output);
	}//end testWithoutOpenRegisterTheStepWarns()

	/**
	 * A failed save is logged and counted, the other rows are still saved,
	 * serialisable rows are read, and rows without an id or of another shape are left alone.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndTheRestIsNormalised(): void {
		$serialisable = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'c', 'name' => 'Kapper', 'industry' => 'Care'];
			}
		};
		$rows = [
			['id' => 'a', 'name' => 'Bakkerij', 'industry' => 'Retail'],
			['id' => 'b', 'name' => 'Garage', 'industry' => 'Mobility'],
			['name' => 'Zonder id', 'industry' => 'Retail'],
			$serialisable,
			'not a row',
		];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'NormalisePartyFormFields: failed to save record',
			['uuid' => 'b', 'schema' => '11', 'error' => 'validation failed']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('NormalisePartyFormFields: 2 record(s) normalised, 1 unchanged, 1 failed to save');

		$service = $this->runOver(config: ['register' => '7', 'client_schema' => '11'], rows: $rows, failIds: ['b'], logger: $logger, output: $output);

		$this->assertSame(
			[
				['id' => 'a', 'name' => 'Bakkerij', 'industry' => ['Retail']],
				['id' => 'c', 'name' => 'Kapper', 'industry' => ['Care']],
			],
			$service->saved
		);
	}//end testAFailedSaveIsCountedAndTheRestIsNormalised()

	/**
	 * A schema whose rows cannot be read is logged and reports nothing done.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsLoggedAndNothingIsSaved(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with(
			'NormalisePartyFormFields: findAll failed',
			$this->callback(static fn (array $context): bool => in_array($context['schema'], ['11', '12'], true) && $context['error'] === 'index offline')
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('NormalisePartyFormFields: 0 record(s) normalised, 0 unchanged, 0 failed to save');

		$service = $this->runOver(config: ['register' => '7', 'client_schema' => '11', 'contact_schema' => '12'], rows: null, logger: $logger, output: $output);

		$this->assertSame([], $service->saved);
	}//end testAnUnreadableSchemaIsLoggedAndNothingIsSaved()
}//end class
