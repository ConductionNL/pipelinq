<?php

/**
 * Golden test for ContactSyncSettingsService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\ContactSyncSettingsService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Every call status() and run() make on the settings, the register, the
 * sync and the logger, and what they return, compared case by case with
 * ContactSyncSettingsGolden.json. Set CONTACT_SYNC_SETTINGS_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\ContactSyncSettingsService
 * @covers \OCA\Pipelinq\Service\LinkedContactRecords
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/ContactSyncSettingsGolden.json';

	/**
	 * Calls made in the current case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		if (getenv('CONTACT_SYNC_SETTINGS_GOLDEN_RECORD') !== false) {
			file_put_contents(
				self::GOLDEN,
				json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The cases: the world each double answers from, and the call to make.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$world = [
			'appConfig' => ['register' => 'reg-1', 'client_schema' => 'sch-client', 'contact_schema' => 'sch-contact'],
			'user' => [],
			'enabled' => true,
			'objects' => [
				'sch-client' => [
					['entity' => ['id' => 'c-1', 'name' => 'Acme', 'contactsUid' => 'uid-1']],
					['array' => ['@self' => ['id' => 'c-2'], 'name' => 'Beta', 'contactsUid' => 'uid-2']],
					['array' => ['id' => 'c-3', 'name' => 'Gamma', 'contactsUid' => '']],
					['entity' => ['name' => 'Delta', 'contactsUid' => 'uid-4']],
					['scalar' => 'nope'],
				],
				'sch-contact' => [
					['entity' => ['id' => 'p-1', 'contactsUid' => 'uid-5']],
					['array' => ['id' => 'p-2', 'name' => 'Eve']],
				],
			],
			'sync' => ['c-1' => 'uid-1', 'c-2' => null, '' => 'uid-4', 'p-1' => 'throw'],
		];
		$status = ['status', ['userId' => 'u-1']];
		$run = ['run', ['userId' => 'u-1', 'now' => '2026-11-02T10:00:00Z']];

		return [
			'status' => ['world' => $world, 'call' => $status],
			'status with a last run and write-back off' => ['world' => [...$world, 'user' => ['contacts_sync_write_back' => '0', 'contacts_sync_last_run' => '{"at":"x","updated":2}']], 'call' => $status],
			'status, broken last run' => ['world' => [...$world, 'user' => ['contacts_sync_last_run' => 'not json']], 'call' => $status],
			'status, contacts disabled' => ['world' => [...$world, 'enabled' => false], 'call' => $status],
			'status, no register' => ['world' => [...$world, 'appConfig' => ['client_schema' => 'sch-client']], 'call' => $status],
			'status, no contact schema' => ['world' => [...$world, 'appConfig' => ['register' => 'reg-1', 'client_schema' => 'sch-client']], 'call' => $status],
			'status, client read throws' => ['world' => [...$world, 'objects' => [...$world['objects'], 'sch-client' => 'throw']], 'call' => $status],
			'run' => ['world' => $world, 'call' => $run],
			'run, nothing linked' => ['world' => [...$world, 'objects' => []], 'call' => $run],
			'run, no register' => ['world' => [...$world, 'appConfig' => []], 'call' => $run],
			'run, contact read throws' => ['world' => [...$world, 'objects' => [...$world['objects'], 'sch-contact' => 'throw']], 'call' => $run],
		];
	}//end cases()

	/**
	 * Run one case against a freshly wired service.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$service = $this->build(class: ContactSyncSettingsService::class, doubles: $this->doubles(world: $case['world']));
		[$method, $args] = $case['call'];
		try {
			$result = ['result' => $service->{$method}(...$args)];
		} catch (\Throwable $e) {
			$result = ['error' => get_class($e) . ': ' . $e->getMessage()];
		}

		return [...$result, 'calls' => $this->calls];
	}//end runCase()

	/**
	 * The doubles the service is built from, by type.
	 *
	 * @param array<string, mixed> $world What the doubles answer.
	 *
	 * @return array<string, object>
	 */
	private function doubles(array $world): array {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (mixed ...$args): string => $this->record(call: 'config.getUserValue', args: $this->passed(class: IConfig::class, method: 'getUserValue', args: $args), result: (string)($world['user'][$args[2]] ?? $args[3]))
		);
		$config->method('setUserValue')->willReturnCallback(
			function (mixed ...$args): void {
				$this->record(call: 'config.setUserValue', args: $this->passed(class: IConfig::class, method: 'setUserValue', args: $args), result: null);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (mixed ...$args): string => $this->record(call: 'appConfig.getValueString', args: $this->passed(class: IAppConfig::class, method: 'getValueString', args: $args), result: (string)($world['appConfig'][$args[1]] ?? $args[2]))
		);

		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('isEnabled')->willReturnCallback(
			fn (): bool => $this->record(call: 'contacts.isEnabled', args: [], result: $world['enabled'])
		);

		$writer = $this->createMock(ContactVcardWriterService::class);
		$writer->method('newCardAddressBookName')->willReturnCallback(
			fn (): string => $this->record(call: 'writer.newCardAddressBookName', args: [], result: 'Contacts')
		);

		$sync = $this->createMock(ContactSyncService::class);
		$sync->method('syncToContacts')->willReturnCallback(
			function (string $objectType, string $objectId) use ($world): ?string {
				$this->record(call: 'sync.syncToContacts', args: [$objectType, $objectId], result: null);
				$answer = 'uid-' . $objectId;
				if (array_key_exists($objectId, $world['sync']) === true) {
					$answer = $world['sync'][$objectId];
				}

				if ($answer === 'throw') {
					throw new RuntimeException('contacts down');
				}

				return $answer;
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (mixed ...$args) use ($world): array {
				$this->record(call: 'objects.findAll', args: $this->passed(class: ObjectServiceInterface::class, method: 'findAll', args: $args), result: null);
				$rows = ($world['objects'][$args[0]['filters']['@self']['schema'] ?? ''] ?? []);
				if ($rows === 'throw') {
					throw new RuntimeException('register down');
				}

				return array_map(fn (array $row): mixed => $this->row(row: $row), $rows);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->record(call: 'logger.' . $level, args: [$message, $context], result: null);
				}
			);
		}

		return [
			IConfig::class => $config,
			IAppConfig::class => $appConfig,
			IContactsManager::class => $contacts,
			ContactSyncService::class => $sync,
			ContactVcardWriterService::class => $writer,
			ObjectServiceInterface::class => $objects,
			LoggerInterface::class => $logger,
		];
	}//end doubles()

	/**
	 * One stored object as the register returns it.
	 *
	 * @param array<string, mixed> $row `entity`, `array` or `scalar` and its data.
	 *
	 * @return mixed
	 */
	private function row(array $row): mixed {
		if (isset($row['entity']) === true) {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($row['entity']);

			return $entity;
		}

		return ($row['array'] ?? $row['scalar']);
	}//end row()

	/**
	 * Build a class by its constructor's parameter types: a double when one
	 * is given, otherwise the real class built the same way.
	 *
	 * @param string $class The class.
	 * @param array<string, object> $doubles Doubles by type.
	 *
	 * @return object
	 */
	private function build(string $class, array $doubles): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (($type instanceof ReflectionNamedType) === false) {
				throw new RuntimeException('untyped parameter ' . $parameter->getName());
			}

			$args[$parameter->getName()] = ($doubles[$type->getName()] ?? $this->build(class: $type->getName(), doubles: $doubles));
		}

		return new $class(...$args);
	}//end build()

	/**
	 * The arguments a call actually passed: trailing ones the mock filled in
	 * with the loaded signature's defaults are dropped.
	 *
	 * @param string $class The mocked type.
	 * @param string $method The method.
	 * @param array<int, mixed> $args The arguments the mock received.
	 *
	 * @return array<int, mixed>
	 */
	private function passed(string $class, string $method, array $args): array {
		$parameters = (new ReflectionMethod($class, $method))->getParameters();
		while ($args !== []) {
			$last = (count($args) - 1);
			$parameter = ($parameters[$last] ?? null);
			if ($parameter === null || $parameter->isDefaultValueAvailable() === false || $parameter->getDefaultValue() !== $args[$last]) {
				break;
			}

			array_pop($args);
		}

		return $args;
	}//end passed()

	/**
	 * Record a call and pass its result through.
	 *
	 * @param string $call What was called.
	 * @param array<int, mixed> $args The arguments.
	 * @param mixed $result The answer.
	 *
	 * @return mixed
	 */
	private function record(string $call, array $args, mixed $result): mixed {
		$this->calls[] = ['call' => $call, 'args' => $args];

		return $result;
	}//end record()
}//end class
