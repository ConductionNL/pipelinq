<?php

/**
 * Unit tests for DemoRegisterImporter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCP\App\IAppManager;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\Demo\DemoUserFields;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\Demo\DemoRegisterImporter
 */
class DemoRegisterImporterTest extends TestCase {
	/**
	 * Calls the fake OpenRegister services received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Stored records the fake object service returns, per schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * The descriptor the fake OpenRegister received on import.
	 *
	 * @var array<string, mixed>
	 */
	public array $importedData = [];

	/**
	 * Build the importer over the real descriptor and fake OpenRegister services.
	 *
	 * @return DemoRegisterImporter
	 */
	private function importer(?string $actingUid = null): DemoRegisterImporter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$appManager->method('getAppVersion')->willReturn('0.5.12');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($key === 'register') ? '7' : ''
		);

		// softDeleteAppImports() exists only on OpenRegister since 2026-09-27, so the
		// stub leaves it out and the importer guards it with method_exists(). This
		// subclass is an OpenRegister new enough to have it.
		$test = $this;
		$configuration = new class($test) extends ConfigurationService {
			public function __construct(private DemoRegisterImporterTest $test) {
			}

			public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
				$this->test->record(['importFromApp', $appId, count($data['components']['objects']), $version, $force]);
				$this->test->importedData = $data;
				return ['objects' => array_fill(0, 3, 'x'), 'skipped' => ['objects' => 1]];
			}

			public function softDeleteAppImports(string $appId): array {
				$this->test->record(['softDeleteAppImports', $appId]);
				return ['softDeleted' => 2];
			}
		};

		$schema = new class {
			public function getId(): int {
				return 42;
			}
		};
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (string|int $slug) use ($schema): object {
				if ($slug !== 'product') {
					throw new \RuntimeException('not found');
				}

				return $schema;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => $this->storedFor((string)$config['filters']['schema'])
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schemaId = null): bool {
				$this->record(['deleteObject', $uuid, (string)$register, (string)$schemaId]);
				return true;
			}
		);

		return new DemoRegisterImporter(
			appManager: $appManager,
			appConfig: $appConfig,
			configurationService: $configuration,
			schemaMapper: $schemaMapper,
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->userSession(uid: $actingUid),
			userManager: $this->userManager(),
			configLoader: new ConfigFileLoaderService($appManager),
			userFields: new DemoUserFields(),
		);
	}//end importer()

	/**
	 * A session with the given user signed in, or nobody.
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return IUserSession
	 */
	private function userSession(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end userSession()

	/**
	 * A server whose only account is `admin`.
	 *
	 * @return IUserManager
	 */
	private function userManager(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		return $users;
	}//end userManager()

	/**
	 * Every user field the example records hand to OpenRegister, as [where, value].
	 *
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private function userValues(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$schemas = (new ConfigFileLoaderService($appManager))->loadConfigurationFile()['components']['schemas'];

		$isUser = static fn (array $p): bool => in_array(($p['format'] ?? ''), ['user', 'username'], true)
			|| ($p['referenceType'] ?? '') === 'nextcloud-user';

		$found = [];
		foreach ($this->importedData['components']['objects'] as $object) {
			foreach ((array)($schemas[$object['@self']['schema']]['properties'] ?? []) as $key => $property) {
				if (array_key_exists($key, $object) === false) {
					continue;
				}

				$where = $object['@self']['slug'] . '.' . $key;
				if ($object[$key] === null || $object[$key] === '') {
					// Empty is a valid "nobody", not a demo name.
					continue;
				}

				if ($isUser($property) === true) {
					$found[] = [$where, $object[$key]];
				} else if (($property['type'] ?? '') === 'array' && $isUser((array)($property['items'] ?? [])) === true) {
					foreach ((array)$object[$key] as $value) {
						$found[] = [$where, $value];
					}
				}
			}
		}

		return $found;
	}//end userValues()

	/**
	 * Every user field points at a real account: the one who loads the examples.
	 *
	 * @return void
	 */
	public function testUserFieldsPointAtTheAdminWhoLoadsThem(): void {
		$this->importer(actingUid: 'admin')->import();

		$values = $this->userValues();
		$this->assertGreaterThan(30, count($values), 'the descriptor has user fields to fix');
		foreach ($values as [$where, $value]) {
			$this->assertSame('admin', $value, $where . ' names a user that does not exist');
		}
	}//end testUserFieldsPointAtTheAdminWhoLoadsThem()

	/**
	 * Without anyone signed in (occ), a demo user is left out rather than invented.
	 *
	 * @return void
	 */
	public function testWithoutASignedInUserDemoUsersAreLeftOut(): void {
		$this->importer(actingUid: null)->import();

		$this->assertSame([], array_filter($this->userValues(), static fn (array $pair): bool => $pair[1] !== 'admin'));
	}//end testWithoutASignedInUserDemoUsersAreLeftOut()

	/**
	 * Record a call a fake service received.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * The stored records of one schema id.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function storedFor(string $schemaId): array {
		return ($this->stored[$schemaId] ?? []);
	}//end storedFor()

	/**
	 * The import runs under its own identity, forced, with the whole descriptor.
	 *
	 * @return void
	 */
	public function testImportUsesItsOwnConfigurationIdentity(): void {
		$result = $this->importer()->import();

		$descriptor = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/pipelinq_example_register.json'), true);
		$this->assertSame(
			[['importFromApp', 'pipelinq.demo', count($descriptor['components']['objects']), '0.5.12', true]],
			$this->calls
		);
		$this->assertSame(['imported' => 3, 'skipped' => 1], $result);
	}//end testImportUsesItsOwnConfigurationIdentity()

	/**
	 * Removal soft-deletes the recorded imports, then the records an old install
	 * received from the register, but only those whose slug and name still match.
	 *
	 * @return void
	 */
	public function testRemoveDeletesOnlyUnchangedExampleRecords(): void {
		$this->stored['42'] = [
			// An example record exactly as the old register seeded it.
			['id' => 'uuid-cappuccino', 'name' => 'Cappuccino', '@self' => ['slug' => 'product-cappuccino']],
			// The same slug, renamed by its owner: theirs now.
			['id' => 'uuid-renamed', 'name' => 'Our house coffee', '@self' => ['slug' => 'product-tshirt-conduction']],
			// A record of the administrator's own.
			['id' => 'uuid-own', 'name' => 'Consultancy day', '@self' => ['slug' => 'consultancy-day']],
		];

		$result = $this->importer()->remove();

		$this->assertSame(['softDeleteAppImports', 'pipelinq.demo'], $this->calls[0]);
		$this->assertSame([['deleteObject', 'uuid-cappuccino', '7', '42']], array_slice($this->calls, 1));
		$this->assertSame(['removed' => 3, 'retained' => 0], $result);
	}//end testRemoveDeletesOnlyUnchangedExampleRecords()
}//end class
