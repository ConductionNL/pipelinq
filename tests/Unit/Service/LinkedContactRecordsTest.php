<?php

/**
 * LinkedContactRecords: the records with a contactsUid, read per type.
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
use OCA\Pipelinq\Service\LinkedContactRecords;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * LinkedContactRecords coverage.
 */
class LinkedContactRecordsTest extends TestCase {

	/**
	 * Records over the given config and per-schema answers.
	 *
	 * @param array<string, string>       $config  App config key => value.
	 * @param array<string, mixed>        $answers Schema id => rows, or a Throwable.
	 * @param array<int, mixed>           $calls   The findAll configs, filled in.
	 * @param LoggerInterface|null        $logger  The logger.
	 *
	 * @return LinkedContactRecords
	 */
	private function records(array $config, array $answers, array &$calls, ?LoggerInterface $logger = null): LinkedContactRecords {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'pipelinq' ? ($config[$key] ?? $default) : 'wrong-app')
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($answers, &$calls) {
				$calls[] = $config;
				$answer = $answers[$config['filters']['@self']['schema']] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new LinkedContactRecords($appConfig, $objects, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end records()

	/**
	 * Every way a read ends: no register reads nothing; a type without a
	 * schema is skipped; only rows with a contactsUid are kept, with the id
	 * from the row or its @self; a failed read is logged and the next type read.
	 *
	 * @return void
	 */
	public function testEveryWayAReadEnds(): void {
		$calls = [];
		$this->assertSame([], $this->records(config: [], answers: [], calls: $calls)->all());
		$this->assertSame([], $calls);

		$calls = [];
		$rows = [
			['id' => 'c1', 'name' => 'Acme', 'contactsUid' => 'u1'],
			['id' => 'c2', 'name' => 'No link'],
			['id' => 'c3', 'contactsUid' => ''],
			['@self' => ['id' => 'c4'], 'name' => 'Self', 'contactsUid' => 'u4'],
			['contactsUid' => 'u5'],
		];
		$records = $this->records(config: ['register' => 'r1', 'client_schema' => 's-client', 'contact_schema' => 's-contact'], answers: ['s-client' => $rows, 's-contact' => [['id' => 'p1', 'name' => 'Piet', 'contactsUid' => 'u9']]], calls: $calls)->all();
		$this->assertSame(
			[
				['type' => 'client', 'id' => 'c1', 'name' => 'Acme'],
				['type' => 'client', 'id' => 'c4', 'name' => 'Self'],
				['type' => 'client', 'id' => '', 'name' => ''],
				['type' => 'contact', 'id' => 'p1', 'name' => 'Piet'],
			],
			$records
		);
		$this->assertSame(
			[
				['filters' => ['@self' => ['register' => 'r1', 'schema' => 's-client']], 'limit' => 500],
				['filters' => ['@self' => ['register' => 'r1', 'schema' => 's-contact']], 'limit' => 500],
			],
			$calls
		);

		$calls = [];
		$records = $this->records(config: ['register' => 'r1', 'contact_schema' => 's-contact'], answers: ['s-contact' => [['id' => 'p1', 'contactsUid' => 'u']]], calls: $calls)->all();
		$this->assertSame([['type' => 'contact', 'id' => 'p1', 'name' => '']], $records);
		$this->assertCount(1, $calls);

		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Pipelinq: linked records not read', ['exception' => 'denied']);
		$records = $this->records(config: ['register' => 'r1', 'client_schema' => 's-client', 'contact_schema' => 's-contact'], answers: ['s-client' => new \RuntimeException('denied'), 's-contact' => [['id' => 'p1', 'name' => 'Piet', 'contactsUid' => 'u9']]], calls: $calls, logger: $logger)->all();
		$this->assertSame([['type' => 'contact', 'id' => 'p1', 'name' => 'Piet']], $records);
		$this->assertCount(2, $calls);
	}//end testEveryWayAReadEnds()
}//end class
