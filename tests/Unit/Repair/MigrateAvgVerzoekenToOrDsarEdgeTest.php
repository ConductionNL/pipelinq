<?php

/**
 * Edge cases of the MigrateAvgVerzoekenToOrDsar repair step.
 *
 * Covers what the mapping and run tests leave out: an unavailable
 * ObjectService, an unreadable target register, existing cases whose notes
 * are no JSON object or that come back as entities, the acting user resolved
 * from the admin group (and a failing lookup), a failed case write, a failed
 * back-reference write, an unparseable due date with an extension, and
 * satellite rows that are unreadable, orphaned or duplicated.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Repair\DsarMigrationRun;
use OCA\Pipelinq\Repair\DsarMigrationSource;
use OCA\Pipelinq\Repair\DsarMigrationSummary;
use OCA\Pipelinq\Repair\DsarSatelliteSchema;
use OCA\Pipelinq\Repair\MigrateAvgVerzoekenToOrDsar;
use OCA\Pipelinq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use OCA\Pipelinq\Tests\Support\LegacyObjectArgs;

/**
 * A logger that keeps every line.
 */
final class RecordingDsarLogger extends AbstractLogger {

	/**
	 * Logged lines as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public array $lines = [];

	/**
	 * Record a line.
	 *
	 * @param mixed               $level   The level.
	 * @param string|\Stringable  $message The message.
	 * @param array<string,mixed> $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->lines[] = [(string)$level, (string)$message, $context];
	}//end log()

	/**
	 * The messages logged at one level.
	 *
	 * @param string $level The level.
	 *
	 * @return list<string>
	 */
	public function at(string $level): array {
		return array_values(array_map(static fn (array $line): string => $line[1], array_filter($this->lines, static fn (array $line): bool => $line[0] === $level)));
	}//end at()
}//end class

/**
 * The migration's failure and fallback paths.
 */
#[CoversClass(MigrateAvgVerzoekenToOrDsar::class)]
#[UsesClass(DsarMigrationRun::class)]
#[UsesClass(DsarMigrationSource::class)]
#[UsesClass(DsarMigrationSummary::class)]
#[UsesClass(DsarSatelliteSchema::class)]
#[UsesClass(RunsUnderSystemIdentity::class)]
final class MigrateAvgVerzoekenToOrDsarEdgeTest extends TestCase {

	/**
	 * Schema-config-key => id.
	 *
	 * @var array<string, string>
	 */
	private const IDS = [
		'register' => '11',
		'avgVerzoek_schema' => '40',
		'bewijsItem_schema' => '41',
		'weigering_schema' => '42',
		'redactieActie_schema' => '43',
	];

	/**
	 * findAll answers keyed by schema id or slug; a Throwable is thrown instead.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Every saveObject call, as its full argument list.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * Save outcomes in call order: 'ok' or a Throwable to throw.
	 *
	 * @var list<mixed>
	 */
	private array $saveOutcomes = [];

	/**
	 * The logger.
	 *
	 * @var RecordingDsarLogger
	 */
	private RecordingDsarLogger $logger;

	/**
	 * Fresh state per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->saves = [];
		$this->saveOutcomes = [];
		$this->logger = new RecordingDsarLogger();
	}//end setUp()

	/**
	 * The ObjectService fake over $this->store.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$answer = $this->store[(string)($config['filters']['schema'] ?? '')] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				$this->saves[] = $args;
				$outcome = array_shift($this->saveOutcomes) ?? 'ok';
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('dsar-' . count($this->saves));
				return $entity;
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * The step under test.
	 *
	 * @param ContainerInterface|null $container    A container to use instead of one over the fake.
	 * @param IGroupManager|null      $groupManager The group manager, if any.
	 *
	 * @return MigrateAvgVerzoekenToOrDsar
	 */
	private function step(?ContainerInterface $container=null, ?IGroupManager $groupManager=null): MigrateAvgVerzoekenToOrDsar {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => self::IDS[$key] ?? $default
		);

		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($this->objectService());
		}

		return new MigrateAvgVerzoekenToOrDsar(appConfig: $appConfig, container: $container, logger: $this->logger, groupManager: $groupManager);
	}//end step()

	/**
	 * The case payloads written to the DSAR register.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function cases(): array {
		return array_values(array_map(static fn (array $args): array => $args[0], array_filter($this->saves, static fn (array $args): bool => ($args[3] ?? null) === 'dataSubjectRequest')));
	}//end cases()

	/**
	 * A source verzoek that maps.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	private static function verzoek(string $id): array {
		return ['id' => $id, 'artikel' => 'art-15-inzage', 'status' => 'ingediend', 'verzoekerContact' => 'contact-' . $id];
	}//end verzoek()

	public function testTheNameNamesBothEnds(): void {
		$this->assertSame(
			'Migrate pipelinq avgVerzoek objects to OpenRegister dataSubjectRequest cases (consume-or-dsar)',
			$this->step()->getName()
		);
	}//end testTheNameNamesBothEnds()

	public function testAnUnavailableObjectServiceSkipsWithAWarning(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Pipelinq AVG→DSAR migration: OpenRegister ObjectService unavailable — skipped.');
		$output->expects($this->never())->method('info');

		$this->step(container: $container)->run($output);
	}//end testAnUnavailableObjectServiceSkipsWithAWarning()

	public function testAnUnreadableTargetRegisterIsLoggedAndTheMigrationStillRuns(): void {
		$this->store = ['40' => [self::verzoek('v1')], 'dataSubjectRequest' => new RuntimeException('register missing')];

		$this->step()->run($this->createStub(IOutput::class));

		$this->assertContains(
			'Pipelinq AVG→DSAR migration: could not read existing dataSubjectRequest cases — register missing',
			$this->logger->at('warning')
		);
		$this->assertCount(1, $this->cases());
		$this->assertSame('contact-v1', $this->cases()[0]['subjectId']);
	}//end testAnUnreadableTargetRegisterIsLoggedAndTheMigrationStillRuns()

	public function testExistingCasesAreReadAsEntitiesAndNotesThatAreNoObjectAreIgnored(): void {
		$entityCase = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['notes' => json_encode(['migratedFromId' => 'v2'])];
			}
		};
		$this->store = [
			'40' => [self::verzoek('v1'), self::verzoek('v2'), 'not a row'],
			'dataSubjectRequest' => [['notes' => 42], ['notes' => 'not json'], $entityCase, 'unreadable'],
		];

		$this->step()->run($this->createStub(IOutput::class));

		// v2 already has a case (read through jsonSerialize); v1 has none, despite the scalar notes.
		$this->assertSame(['contact-v1'], array_column($this->cases(), 'subjectId'));
	}//end testExistingCasesAreReadAsEntitiesAndNotesThatAreNoObjectAreIgnored()

	public function testTheFirstAdminActsForBothWrites(): void {
		$admin = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(['admin' => $admin]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->atLeastOnce())->method('get')->with('admin')->willReturn($group);
		$this->store = ['40' => [self::verzoek('v1')]];

		$this->step(groupManager: $groupManager)->run($this->createStub(IOutput::class));

		$this->assertCount(2, $this->saves);
		foreach ($this->saves as $args) {
			$this->assertSame($admin, $args[10], 'currentUser');
		}
	}//end testTheFirstAdminActsForBothWrites()

	public function testAnAdminGroupWithoutUsersOrAFailingLookupActsAsNobody(): void {
		$empty = $this->createMock(IGroup::class);
		$empty->method('getUsers')->willReturn([]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnOnConsecutiveCalls($empty, $this->throwException(new RuntimeException('group backend down')));
		$this->store = ['40' => [self::verzoek('v1')]];

		$this->step(groupManager: $groupManager)->run($this->createStub(IOutput::class));

		$this->assertCount(2, $this->saves);
		$this->assertNull($this->saves[0][10]);
		$this->assertNull($this->saves[1][10]);
		$this->assertContains('Pipelinq AVG→DSAR migration: could not resolve an acting user — group backend down', $this->logger->at('warning'));
	}//end testAnAdminGroupWithoutUsersOrAFailingLookupActsAsNobody()

	public function testAFailedCaseWriteIsLoggedAndNothingIsWrittenBack(): void {
		$this->store = ['40' => [self::verzoek('v1')]];
		$this->saveOutcomes = [new RuntimeException('validation failed')];

		$this->step()->run($this->createStub(IOutput::class));

		$this->assertCount(1, $this->saves);
		$errors = array_values(array_filter($this->logger->lines, static fn (array $line): bool => $line[0] === 'error'));
		$this->assertSame(
			[['error', 'Pipelinq AVG→DSAR migration: failed to migrate an avgVerzoek', ['id' => 'v1', 'exception' => 'validation failed']]],
			$errors
		);
	}//end testAFailedCaseWriteIsLoggedAndNothingIsWrittenBack()

	public function testAFailedBackReferenceStillCountsAsMigrated(): void {
		$this->store = ['40' => [self::verzoek('v1')]];
		$this->saveOutcomes = ['ok', new RuntimeException('folder unreachable')];

		$this->step()->run($this->createStub(IOutput::class));

		$this->assertCount(2, $this->saves);
		$this->assertSame('dsar-1', $this->saves[1][0]['migratedTo']);
		$infos = array_values(array_filter($this->logger->lines, static fn (array $line): bool => $line[0] === 'info' && str_contains($line[1], 'back-reference on the source could not be written')));
		$this->assertCount(1, $infos);
		$this->assertSame(['verzoek' => 'v1', 'case' => 'dsar-1', 'exception' => 'folder unreachable'], $infos[0][2]);
		$this->assertSame([], $this->logger->at('error'));
	}//end testAFailedBackReferenceStillCountsAsMigrated()

	public function testAnExtensionOnAnUnparseableDueDateKeepsOnlyTheReason(): void {
		$this->store = ['40' => [self::verzoek('v1') + ['wettelijkeTermijnVerloopt' => 'next tuesday-ish!', 'verlengdMet' => 14, 'verlengingsgrond' => 'complex']]];

		$this->step()->run($this->createStub(IOutput::class));

		$case = $this->cases()[0];
		$this->assertSame('complex', $case['extensionReason']);
		$this->assertArrayNotHasKey('extendedUntil', $case);
	}//end testAnExtensionOnAnUnparseableDueDateKeepsOnlyTheReason()

	public function testUnreadableOrphanedAndDuplicateSatellitesAreLeftOut(): void {
		$this->store = [
			'40' => [self::verzoek('v1')],
			'41' => ['junk', ['id' => 'b0', 'requestId' => ''], ['id' => 'b1', 'requestId' => 'v1', 'contentHash' => 'sha256:one']],
			'42' => [
				'junk',
				['id' => 'w0', 'requestId' => ''],
				['id' => 'w1', 'requestId' => 'v1', 'grond' => 'art-23-lid-1-sub-g'],
				['id' => 'w2', 'requestId' => 'v1', 'grond' => 'art-23-lid-1-sub-j'],
			],
			'43' => new RuntimeException('schema gone'),
		];

		$this->step()->run($this->createStub(IOutput::class));

		$case = $this->cases()[0];
		$this->assertSame(['sha256:one'], array_column($case['evidence'], 'contentHash'));
		// The first weigering per verzoek wins.
		$this->assertSame('professional-secrecy', $case['denialGround']);
		$this->assertSame([], $case['redactions'] ?? []);
	}//end testUnreadableOrphanedAndDuplicateSatellitesAreLeftOut()
}//end class
