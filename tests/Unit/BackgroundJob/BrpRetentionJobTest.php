<?php

/**
 * Unit tests for BrpRetentionJob: BRP person records past their retention date are deleted and their contact loses the verified BSN.
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
use OCA\Pipelinq\BackgroundJob\BrpRetentionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for BrpRetentionJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\BrpRetentionJob
 */
class BrpRetentionJobTest extends TestCase {

	/**
	 * Record a fake ObjectService's calls.
	 *
	 * @param array<int, mixed>                   $records  The person records findAll answers.
	 * @param array<string, array<string, mixed>> $contacts Contacts by id for find().
	 * @param array<string, bool>                 $failing  Which operations throw (delete, find, save).
	 *
	 * @return object
	 */
	private static function objects(array $records, array $contacts = [], array $failing = []): object {
		return new class ($records, $contacts, $failing) {
			/** @var array<int, array<mixed>> */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed>                   $records  The records.
			 * @param array<string, array<string, mixed>> $contacts The contacts.
			 * @param array<string, bool>                 $failing  The failing operations.
			 */
			public function __construct(private array $records, private array $contacts, private array $failing) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				return $this->records;
			}//end findAll()

			/**
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * @param string $uuid The object.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid): bool {
				if (isset($this->failing['delete']) === true) {
					throw new RuntimeException('delete refused');
				}

				$this->calls[] = ['deleteObject', $uuid];
				return true;
			}//end deleteObject()

			/**
			 * @param string $id       The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register, string $schema): ?array {
				if (isset($this->failing['find']) === true) {
					throw new RuntimeException('contact unreadable');
				}

				return ($this->contacts[$id] ?? null);
			}//end find()

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, string>   $extend   Extensions.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
				$this->calls[] = ['saveObject', $object, $register, $schema, $uuid];
				return $object;
			}//end saveObject()
		};
	}//end objects()

	/**
	 * Run the job with app config and an ObjectService.
	 *
	 * @param array<string, string> $config  App config strings.
	 * @param object|null           $objects The ObjectService.
	 * @param LoggerInterface|null  $logger  The logger.
	 *
	 * @return void
	 */
	private function runJob(array $config, ?object $objects, ?LoggerInterface $logger = null): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default));
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		}

		$job = new BrpRetentionJob($this->createMock(ITimeFactory::class), $appConfig, $container, ($logger ?? $this->createMock(LoggerInterface::class)));
		(new ReflectionMethod(BrpRetentionJob::class, 'run'))->invoke($job, null);
	}//end runJob()

	private const CONFIG = ['register' => 'reg', 'brpPersoon_schema' => 'persoon', 'contact_schema' => 'contact'];

	/**
	 * Expired persons are deleted and their linked contact loses the verified BSN; the rest stay.
	 *
	 * @return void
	 */
	public function testExpiredPersonsAreDeletedAndTheirContactReset(): void {
		$serialisable = new class () implements \JsonSerializable {
			/** @return array<string, string> */
			public function jsonSerialize(): array {
				return ['id' => 'p-2', 'retentionTo' => '2001-01-01T00:00:00Z'];
			}//end jsonSerialize()
		};
		$objects = self::objects(
			[
				['@self' => ['id' => 'p-1'], 'retentionTo' => '2000-01-01', 'gekoppeldContact' => 'c-1'],
				$serialisable,
				['id' => 'p-3', 'retentionTo' => '2999-01-01'],
				['id' => 'p-4'],
				['id' => 'p-5', 'retentionTo' => 'not a date'],
				'junk',
			],
			['c-1' => ['brpPersonId' => 'p-1', 'verifiedBSN' => true, 'name' => 'Anna']]
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 2]);

		$this->runJob(self::CONFIG, $objects, $logger);

		$this->assertSame(['findAll', ['filters' => ['register' => 'reg', 'schema' => 'persoon']]], $objects->calls[0]);
		$deleted = array_values(array_map(static fn (array $c): string => $c[1], array_filter($objects->calls, static fn (array $c): bool => $c[0] === 'deleteObject')));
		$this->assertSame(['p-1', 'p-2'], $deleted);
		$saves = array_values(array_filter($objects->calls, static fn (array $c): bool => $c[0] === 'saveObject'));
		$this->assertSame([['saveObject', ['brpPersonId' => 'p-1', 'verifiedBSN' => false, 'name' => 'Anna'], 'reg', 'contact', 'c-1']], $saves);
	}//end testExpiredPersonsAreDeletedAndTheirContactReset()

	/**
	 * A contact now linked to another person, or one that is gone, is left alone.
	 *
	 * @return void
	 */
	public function testAContactLinkedElsewhereIsLeftAlone(): void {
		$objects = self::objects(
			[
				['id' => 'p-1', 'retentionTo' => '2000-01-01', 'gekoppeldContact' => 'c-1'],
				['id' => 'p-2', 'retentionTo' => '2000-01-01', 'gekoppeldContact' => 'c-gone'],
			],
			['c-1' => ['brpPersonId' => 'p-other', 'verifiedBSN' => true]]
		);

		$this->runJob(self::CONFIG, $objects);
		$this->assertSame([], array_filter($objects->calls, static fn (array $c): bool => $c[0] === 'saveObject'));
	}//end testAContactLinkedElsewhereIsLeftAlone()

	/**
	 * Without a contact schema the person is deleted and no contact is read.
	 *
	 * @return void
	 */
	public function testWithoutAContactSchemaOnlyThePersonIsDeleted(): void {
		$objects = self::objects([['id' => 'p-1', 'retentionTo' => '2000-01-01', 'gekoppeldContact' => 'c-1']], [], ['find' => true]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runJob(['register' => 'reg', 'brpPersoon_schema' => 'persoon'], $objects, $logger);
		$this->assertContains(['deleteObject', 'p-1'], $objects->calls);
	}//end testWithoutAContactSchemaOnlyThePersonIsDeleted()

	/**
	 * A refused delete is logged and not counted; a failing contact reset is logged.
	 *
	 * @return void
	 */
	public function testFailuresAreLoggedPerRecord(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('BRP retention: delete failed', ['uuid' => 'p-1', 'error' => 'delete refused']);
		$logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 0]);
		$this->runJob(self::CONFIG, self::objects([['id' => 'p-1', 'retentionTo' => '2000-01-01']], [], ['delete' => true]), $logger);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('BRP retention: contact reset failed', ['contactId' => 'c-1', 'error' => 'contact unreadable']);
		$logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 1]);
		$this->runJob(self::CONFIG, self::objects([['id' => 'p-1', 'retentionTo' => '2000-01-01', 'gekoppeldContact' => 'c-1']], [], ['find' => true]), $logger);
	}//end testFailuresAreLoggedPerRecord()

	/**
	 * Without the register or person schema nothing is read; a failing store is logged.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredOrUnavailableStoreDeletesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('BRP retention: schemas not configured; skipping');
		$this->runJob(['register' => 'reg'], null, $logger);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => (self::CONFIG[$key] ?? $default));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('BRP retention job failed', ['error' => 'no OpenRegister']);
		$job = new BrpRetentionJob($this->createMock(ITimeFactory::class), $appConfig, $container, $logger);
		(new ReflectionMethod(BrpRetentionJob::class, 'run'))->invoke($job, null);
	}//end testAnUnconfiguredOrUnavailableStoreDeletesNothing()
}//end class
