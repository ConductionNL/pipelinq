<?php

/**
 * Unit tests for BrpRetentionJob: deleting BRP person records past their retention date and resetting the linked contact.
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
use OCA\Pipelinq\BackgroundJob\BrpRetentionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The BRP retention sweep. Dates are far in the past or future so the
 * outcome does not depend on the clock.
 */
#[CoversClass(BrpRetentionJob::class)]
class BrpRetentionJobTest extends TestCase {

	private const PAST = '2001-01-01T00:00:00Z';

	private const FUTURE = '2999-01-01T00:00:00Z';

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Configured string values.
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
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->config    = ['register' => 'crm', 'brpPersoon_schema' => 'brpPerson', 'contact_schema' => 'contact'];
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);
	}//end setUp()

	/**
	 * A recording object service.
	 *
	 * @param array<int, mixed>         $persons  What findAll answers.
	 * @param array<string, mixed>      $contacts Contact id => what find answers.
	 * @param array<int, string>        $failOn   Uuids whose delete throws.
	 *
	 * @return object
	 */
	private function objects(array $persons, array $contacts = [], array $failOn = []): object {
		$objects = new class {
			/** @var array<int, mixed> */
			public array $persons = [];

			/** @var array<string, mixed> */
			public array $contacts = [];

			/** @var array<int, string> */
			public array $failOn = [];

			/** @var array<int, array<string, mixed>> */
			public array $findAllConfigs = [];

			/** @var array<int, string> */
			public array $deleted = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/** @var array<int, string> */
			public array $scope = [];

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->findAllConfigs[] = $config;
				return $this->persons;
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->scope[] = 'register:' . $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->scope[] = 'schema:' . $schema;
				return $this;
			}

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid): bool {
				if (in_array($uuid, $this->failOn, true) === true) {
					throw new \RuntimeException('locked ' . $uuid);
				}

				$this->deleted[] = $uuid;
				return true;
			}

			/**
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				$found = $this->contacts[$id] ?? null;
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param array<int, string>   $extend   Extends.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};
		$objects->persons  = $persons;
		$objects->contacts = $contacts;
		$objects->failOn   = $failOn;
		$this->container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		return $objects;
	}//end objects()

	/**
	 * Run one sweep.
	 *
	 * @return void
	 */
	private function sweep(): void {
		$job = new BrpRetentionJob($this->createMock(ITimeFactory::class), $this->appConfig, $this->container, $this->logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end sweep()

	/**
	 * Without a register or person schema the sweep skips and never resolves OpenRegister.
	 *
	 * @return void
	 */
	public function testSkipsWhenThePersonSchemaIsNotConfigured(): void {
		$this->config['brpPersoon_schema'] = '';
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->once())->method('info')->with('BRP retention: schemas not configured; skipping');

		$this->sweep();
	}//end testSkipsWhenThePersonSchemaIsNotConfigured()

	/**
	 * Only persons whose retention date has passed are deleted; others and
	 * those without or with an unreadable date are kept.
	 *
	 * @return void
	 */
	public function testDeletesOnlyExpiredPersons(): void {
		$objects = $this->objects([
			['id' => 'p-old', 'retentionTo' => self::PAST],
			['id' => 'p-new', 'retentionTo' => self::FUTURE],
			['id' => 'p-none'],
			['id' => 'p-bad', 'retentionTo' => 'not a date'],
			['@self' => ['id' => 'p-self'], 'id' => 'ignored', 'retentionTo' => self::PAST],
		]);
		$this->logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 2]);

		$this->sweep();

		$this->assertSame([['filters' => ['register' => 'crm', 'schema' => 'brpPerson']]], $objects->findAllConfigs);
		$this->assertSame(['p-old', 'p-self'], $objects->deleted);
		$this->assertSame(['register:crm', 'schema:brpPerson', 'register:crm', 'schema:brpPerson'], $objects->scope);
	}//end testDeletesOnlyExpiredPersons()

	/**
	 * A serialisable entity is read through jsonSerialize.
	 *
	 * @return void
	 */
	public function testReadsEntitiesThroughJsonSerialize(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'p-entity', 'retentionTo' => '2001-06-01'];
			}
		};
		$objects = $this->objects([$entity, new \stdClass()]);

		$this->sweep();

		$this->assertSame(['p-entity'], $objects->deleted);
	}//end testReadsEntitiesThroughJsonSerialize()

	/**
	 * The linked contact pointing at the deleted person loses its verified BSN flag
	 * and keeps its pointer.
	 *
	 * @return void
	 */
	public function testResetsTheLinkedContact(): void {
		$objects = $this->objects(
			[['id' => 'p-1', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-1']],
			['c-1' => ['id' => 'c-1', 'brpPersonId' => 'p-1', 'verifiedBSN' => true, 'name' => 'Jan']]
		);

		$this->sweep();

		$this->assertSame(
			[[
				'object' => ['id' => 'c-1', 'brpPersonId' => 'p-1', 'verifiedBSN' => false, 'name' => 'Jan'],
				'register' => 'crm',
				'schema' => 'contact',
				'uuid' => 'c-1',
			]],
			$objects->saved
		);
	}//end testResetsTheLinkedContact()

	/**
	 * A contact that now points at another person, or that is gone, is left alone.
	 *
	 * @return void
	 */
	public function testLeavesAContactPointingElsewhereAlone(): void {
		$objects = $this->objects(
			[
				['id' => 'p-1', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-1'],
				['id' => 'p-2', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-gone'],
			],
			['c-1' => ['id' => 'c-1', 'brpPersonId' => 'p-9', 'verifiedBSN' => true]]
		);

		$this->sweep();

		$this->assertSame(['p-1', 'p-2'], $objects->deleted);
		$this->assertSame([], $objects->saved);
	}//end testLeavesAContactPointingElsewhereAlone()

	/**
	 * Without a contact schema the contact is not touched.
	 *
	 * @return void
	 */
	public function testWithoutAContactSchemaTheContactIsNotTouched(): void {
		$this->config['contact_schema'] = '';
		$objects = $this->objects(
			[['id' => 'p-1', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-1']],
			['c-1' => ['id' => 'c-1', 'brpPersonId' => 'p-1']]
		);

		$this->sweep();

		$this->assertSame(['p-1'], $objects->deleted);
		$this->assertSame([], $objects->saved);
	}//end testWithoutAContactSchemaTheContactIsNotTouched()

	/**
	 * A failing delete is logged, not counted, and leaves the contact alone; the sweep continues.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsLoggedAndNotCounted(): void {
		$objects  = $this->objects(
			[
				['id' => 'p-1', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-1'],
				['id' => 'p-2', 'retentionTo' => self::PAST],
			],
			['c-1' => ['id' => 'c-1', 'brpPersonId' => 'p-1']],
			['p-1']
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(function (string $m, array $c) use (&$warnings): void {
			$warnings[] = [$m, $c];
		});
		$this->logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 1]);

		$this->sweep();

		$this->assertSame([['BRP retention: delete failed', ['uuid' => 'p-1', 'error' => 'locked p-1']]], $warnings);
		$this->assertSame(['p-2'], $objects->deleted);
		$this->assertSame([], $objects->saved);
	}//end testAFailingDeleteIsLoggedAndNotCounted()

	/**
	 * A failing contact lookup is logged; the person still counts as deleted.
	 *
	 * @return void
	 */
	public function testAFailingContactResetIsLogged(): void {
		$this->objects(
			[['id' => 'p-1', 'retentionTo' => self::PAST, 'gekoppeldContact' => 'c-1']],
			['c-1' => new \RuntimeException('contact schema gone')]
		);
		$this->logger->expects($this->once())->method('warning')
			->with('BRP retention: contact reset failed', ['contactId' => 'c-1', 'error' => 'contact schema gone']);
		$this->logger->expects($this->once())->method('info')->with('BRP retention sweep complete', ['deleted' => 1]);

		$this->sweep();
	}//end testAFailingContactResetIsLogged()

	/**
	 * When OpenRegister cannot be resolved the job logs one error.
	 *
	 * @return void
	 */
	public function testAnUnavailableOpenRegisterIsLoggedAsError(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$this->logger->expects($this->once())->method('error')
			->with('BRP retention job failed', ['error' => 'not installed']);

		$this->sweep();
	}//end testAnUnavailableOpenRegisterIsLoggedAsError()
}//end class
