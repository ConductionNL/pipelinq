<?php

/**
 * Unit tests for TicketMunicipalityBackfillService and its repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Repair\BackfillTicketMunicipality;
use OCA\Pipelinq\Service\ObjectEventSilence;
use OCA\Pipelinq\Service\TicketMunicipalityBackfillService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Existing tickets take their client's municipality once, silently, and a ticket that has one keeps it.
 */
class TicketMunicipalityBackfillServiceTest extends TestCase {

	/**
	 * The stored objects, by schema id and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every write made.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Whether the system scope is up.
	 *
	 * @var bool
	 */
	private bool $inScope = false;

	/**
	 * The administrator written as.
	 *
	 * @var IUser
	 */
	private IUser $admin;

	/**
	 * Seed two clients and four tickets.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->admin = $this->createMock(IUser::class);
		$this->store = [
			'sch-client' => [
				'c-goes'  => ['@self' => ['id' => 'c-goes'], 'name' => 'Bakkerij', 'municipality' => 'mun-goes'],
				'c-none'  => ['@self' => ['id' => 'c-none'], 'name' => 'Zonder gemeente'],
			],
			'sch-ticket' => [
				't-1' => ['@self' => ['id' => 't-1', 'name' => 'Vraag 1'], 'title' => 'Vraag 1', 'client' => 'c-goes'],
				't-2' => ['@self' => ['id' => 't-2'], 'title' => 'Vraag 2', 'client' => ['@self' => ['id' => 'c-goes']]],
				't-3' => ['@self' => ['id' => 't-3'], 'title' => 'Vraag 3', 'client' => 'c-goes', 'municipality' => 'mun-veere'],
				't-4' => ['@self' => ['id' => 't-4'], 'title' => 'Vraag 4', 'client' => 'c-none'],
				't-5' => ['@self' => ['id' => 't-5'], 'title' => 'Vraag 5'],
			],
		];
	}//end setUp()

	/**
	 * The service over the in-memory store.
	 *
	 * @param bool     $supported   Whether OpenRegister can withhold events.
	 * @param bool     $entersScope Whether runAsSystem raises the scope.
	 * @param string[] $failOn      Uuids whose save throws.
	 * @param bool     $configured  Whether the schemas are configured.
	 *
	 * @return TicketMunicipalityBackfillService The service.
	 */
	private function service(bool $supported=true, bool $entersScope=true, array $failOn=[], bool $configured=true): TicketMunicipalityBackfillService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => match ($key) {
				'register' => 'reg-1',
				'client_schema' => 'sch-client',
				'ticket_schema' => ($configured === true ? 'sch-ticket' : ''),
				default => $default,
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(
			function (callable $operation) use ($entersScope) {
				$this->inScope = $entersScope;
				try {
					return $operation();
				} finally {
					$this->inScope = false;
				}
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				self::assertFalse($_rbac);
				self::assertFalse($_multitenancy);
				return array_values($this->store[$config['filters']['schema']] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend=[],
				string|int|null $register=null,
				string|int|null $schema=null,
				?string $uuid=null,
				bool $_rbac=true,
				bool $_multitenancy=true,
				bool $silent=false,
				bool $_validation=true,
				?array $uploadedFiles=null,
				?IUser $currentUser=null,
			) use ($failOn): ObjectEntity {
				$this->writes[] = ['uuid' => $uuid, 'schema' => $schema, 'object' => $object, 'silent' => $silent, 'inScope' => $this->inScope, 'user' => $currentUser];
				if (in_array($uuid, $failOn, true) === true) {
					throw new RuntimeException('refused');
				}

				$this->store[(string) $schema][(string) $uuid] = $object + ['@self' => ['id' => $uuid]];
				return new ObjectEntity();
			}
		);

		$silence = $this->createMock(ObjectEventSilence::class);
		$silence->method('isSupported')->willReturn($supported);
		$silence->method('isActive')->willReturnCallback(fn (): bool => $supported && $this->inScope);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(['admin' => $this->admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		return new TicketMunicipalityBackfillService(
			appConfig: $appConfig,
			objectService: $objects,
			silence: $silence,
			groupManager: $groups,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Run twice: the two tickets of the Goes client are filled in once, silently
	 * in the system scope as an administrator; the others are left alone.
	 *
	 * @return void
	 */
	public function testFillsEachTicketOnceAcrossTwoRuns(): void {
		self::assertSame(['status' => 'done', 'filled' => 2, 'skipped' => 3, 'failed' => 0], $this->service()->backfill());
		self::assertSame(['status' => 'done', 'filled' => 0, 'skipped' => 5, 'failed' => 0], $this->service()->backfill());

		self::assertSame(['t-1', 't-2'], array_column($this->writes, 'uuid'));
		foreach ($this->writes as $write) {
			self::assertSame('sch-ticket', $write['schema']);
			self::assertSame('mun-goes', $write['object']['municipality']);
			self::assertArrayNotHasKey('@self', $write['object']);
			self::assertTrue($write['silent']);
			self::assertTrue($write['inScope']);
			self::assertSame($this->admin, $write['user']);
		}

		self::assertSame('Vraag 1', $this->writes[0]['object']['title']);
		self::assertSame('mun-veere', $this->store['sch-ticket']['t-3']['municipality']);
		self::assertArrayNotHasKey('municipality', $this->store['sch-ticket']['t-4']);
	}//end testFillsEachTicketOnceAcrossTwoRuns()

	/**
	 * A ticket that cannot be saved is counted and the rest go on.
	 *
	 * @return void
	 */
	public function testCountsAFailedSaveAndGoesOn(): void {
		self::assertSame(['status' => 'done', 'filled' => 1, 'skipped' => 3, 'failed' => 1], $this->service(failOn: ['t-1'])->backfill());
		self::assertSame('mun-goes', $this->store['sch-ticket']['t-2']['municipality']);
	}//end testCountsAFailedSaveAndGoesOn()

	/**
	 * Without the schemas, without a silent path or without the scope, nothing is written.
	 *
	 * @return void
	 */
	public function testWritesNothingWhenItCannotBeSilentOrIsNotSetUp(): void {
		self::assertSame('not-configured', $this->service(configured: false)->backfill()['status']);
		self::assertSame('no-silent-path', $this->service(supported: false)->backfill()['status']);
		self::assertSame('scope-inactive', $this->service(entersScope: false)->backfill()['status']);
		self::assertSame([], $this->writes);
	}//end testWritesNothingWhenItCannotBeSilentOrIsNotSetUp()

	/**
	 * The repair step reports the counts, or why nothing was filled in.
	 *
	 * @return void
	 */
	public function testTheRepairStepReports(): void {
		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line) use (&$lines): void {
			$lines[] = 'info: '.$line;
		});
		$output->method('warning')->willReturnCallback(function (string $line) use (&$lines): void {
			$lines[] = 'warning: '.$line;
		});

		(new BackfillTicketMunicipality(service: $this->service()))->run($output);
		(new BackfillTicketMunicipality(service: $this->service(supported: false)))->run($output);

		self::assertSame(
			[
				"info: BackfillTicketMunicipality: 2 ticket(s) given their client's municipality, 3 left as they were, 0 could not be saved.",
				'warning: BackfillTicketMunicipality: no ticket filled in, because this OpenRegister cannot save without notifying anyone.',
			],
			$lines
		);
		self::assertStringContainsString('municipality', (new BackfillTicketMunicipality(service: $this->service()))->getName());
	}//end testTheRepairStepReports()
}//end class
