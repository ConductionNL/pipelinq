<?php

/**
 * Unit tests for VocabularyUsage.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\VocabularyUsage;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Counting the records that use a vocabulary value.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
 */
class VocabularyUsageTest extends TestCase {
	/**
	 * An app config answering the given keys.
	 *
	 * @param array<string, string> $values The values by key.
	 *
	 * @return IAppConfig The double.
	 */
	private function config(array $values): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		return $config;
	}//end config()

	/**
	 * The count goes to OpenRegister with the register, the schema, the value and the extra filters.
	 *
	 * @return void
	 */
	public function testCountsTheRecordsHoldingTheValue(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('count')
			->with(['filters' => ['ticketType' => 'request', 'register' => '7', 'schema' => '12', 'channel' => 'Email']])
			->willReturn(4);

		$usage = new VocabularyUsage($this->config(['register' => '7', 'ticket_schema' => '12']), $objects);

		$this->assertSame(4, $usage->countUses('ticket_schema', 'channel', 'Email', ['ticketType' => 'request']));
	}//end testCountsTheRecordsHoldingTheValue()

	/**
	 * Without a configured schema nothing is counted.
	 *
	 * @return void
	 */
	public function testCountsNothingWithoutASchema(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('count');

		$usage = new VocabularyUsage($this->config(['register' => '7']), $objects);

		$this->assertSame(0, $usage->countUses('lead_schema', 'source', 'Website'));
	}//end testCountsNothingWithoutASchema()
}//end class
