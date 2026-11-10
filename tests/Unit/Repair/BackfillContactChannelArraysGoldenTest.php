<?php

/**
 * Golden test for the contact channel backfill repair step.
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
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Repair\BackfillContactChannelArrays;
use OCA\Pipelinq\Tests\Unit\Support\SaveCallShape;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every backfill path: what is read, what is saved (by parameter name, as
 * which user), what is logged and printed. Compared with
 * BackfillContactChannelArraysGolden.json; set CHANNEL_BACKFILL_GOLDEN_RECORD=1
 * to rewrite it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Repair\BackfillContactChannelArrays
 * @covers \OCA\Pipelinq\Repair\Support\SchemaWriteTarget
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 *
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */
class BackfillContactChannelArraysGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/BackfillContactChannelArraysGolden.json';

	/**
	 * What the current scenario did, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('CHANNEL_BACKFILL_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: config, stored rows and how the world answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$rows = [
			'client' => [
				['id' => 'c-1', 'name' => 'Both', 'email' => ' a@x.test ', 'phone' => '0612'],
				['id' => 'c-2', 'email' => 'b@x.test', 'emails' => [['value' => 'kept']]],
				['id' => 'c-3', 'name' => 'Nothing'],
				['email' => 'noid@x.test'],
				['id' => '', 'email' => 'emptyid@x.test'],
				['id' => 77, 'phone' => '010'],
				['id' => 'c-4', 'email' => 'c@x.test', 'emails' => 'not a list', 'phones' => []],
				['id' => 'c-5', 'email' => '   ', 'phone' => 12345],
			],
			'contact' => [
				['id' => 'p-1', 'email' => 'p@x.test', 'phones' => [['value' => '1']], 'phone' => '2'],
				'entity:p-2',
				'entity-not-array',
				'not a row',
			],
		];
		return [
			'all rows'                    => ['rows' => $rows],
			'save throws for one row'     => ['rows' => $rows, 'throwOn' => 'c-1'],
			'no admin'                    => ['rows' => $rows, 'admins' => []],
			'no admin group'              => ['rows' => $rows, 'admins' => null],
			'two admins'                  => ['rows' => ['client' => [['id' => 'c-1', 'email' => 'x@y']]], 'admins' => ['zed' => 'zed', 'amy' => 'amy']],
			'client schema not set'       => ['rows' => $rows, 'config' => ['client_schema' => '']],
			'findAll throws'              => ['rows' => $rows, 'findThrows' => 'schema-client'],
			'register not configured'     => ['rows' => $rows, 'config' => ['register' => '']],
			'ObjectService unavailable'   => ['rows' => $rows, 'noObjectService' => true],
			'nothing stored'              => ['rows' => []],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh repair step.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->trace = [];
		$config = ($scenario['config'] ?? []) + ['register' => 'reg-1', 'client_schema' => 'schema-client', 'contact_schema' => 'schema-contact'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->objectDouble(scenario: $scenario);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $scenario): object {
				if (($scenario['noObjectService'] ?? false) === true) {
					throw new RuntimeException('no such service: ' . $id);
				}

				return $objects;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$admins = array_key_exists('admins', $scenario) === true ? $scenario['admins'] : ['root' => 'root'];
		if ($admins === null) {
			$groupManager->method('get')->willReturn(null);
		} else {
			$users = [];
			foreach ($admins as $key => $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$users[$key] = $user;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($users);
			$groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $gid === 'admin' ? $group : null);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->trace[] = ['output' => $level, 'message' => $message];
				}
			);
		}

		$step = new BackfillContactChannelArrays(
			appConfig: $appConfig,
			container: $container,
			groupManager: $groupManager,
			logger: $logger,
		);
		$step->run(output: $output);

		return $this->trace;
	}//end runScenario()

	/**
	 * An ObjectService mock that records findAll and saveObject by parameter name.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectDouble(array $scenario): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (...$args) use ($scenario): array {
				$named = $this->named(method: 'findAll', args: $args);
				$this->trace[] = ['findAll' => $named];
				$schema = (string)($named['config']['filters']['schema'] ?? '');
				if (($scenario['findThrows'] ?? null) === $schema) {
					throw new RuntimeException('find failed');
				}

				return array_map(fn (mixed $row): mixed => $this->row(row: $row), ($scenario['rows'][substr($schema, 7)] ?? []));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($scenario): ObjectEntityInterface {
				$named = SaveCallShape::named(args: $args);
				if (($named['currentUser'] ?? null) instanceof IUser) {
					$named['currentUser'] = 'user:' . $named['currentUser']->getUID();
				}

				$this->trace[] = ['saveObject' => $named];
				if (($scenario['throwOn'] ?? null) === ($named['uuid'] ?? null)) {
					throw new RuntimeException('save failed');
				}

				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		return $objects;
	}//end objectDouble()

	/**
	 * Turn a scenario row into what findAll returns.
	 *
	 * @param mixed $row The scenario row.
	 *
	 * @return mixed
	 */
	private function row(mixed $row): mixed {
		if (is_string($row) === true && str_starts_with($row, 'entity') === true) {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($row === 'entity:p-2' ? ['id' => 'p-2', 'phone' => '+31 6'] : 'scalar');
			return $entity;
		}

		return $row;
	}//end row()

	/**
	 * Name mock call arguments by the contract's parameters, leaving out defaults.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectServiceInterface::class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end named()
}//end class
