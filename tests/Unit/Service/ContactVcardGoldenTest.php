<?php

/**
 * Golden test for ContactVcardService.
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
use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\ContactVcardWriterService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Every call syncToContacts() and provisionContactFromForm() make on the
 * contacts manager, the register, the vCard builder and writer and the
 * logger, and what they return, compared case by case with
 * ContactVcardGolden.json. Set CONTACT_VCARD_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\ContactVcardService
 * @covers \OCA\Pipelinq\Service\ContactObjectReader
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactVcardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/ContactVcardGolden.json';

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

		if (getenv('CONTACT_VCARD_GOLDEN_RECORD') !== false) {
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
			'enabled' => true,
			'register' => 'reg-contact',
			'schemas' => ['client_schema' => 'sch-client', 'contact_schema' => 'sch-contact'],
			'found' => ['id' => 'o-1', 'name' => 'Acme', 'email' => 'info@acme.example'],
			'hits' => [],
			'written' => 'uid-new',
		];
		$sync = static fn (string $type, string $id = 'o-1'): array => ['syncToContacts', ['objectType' => $type, 'objectId' => $id]];
		$form = ['name' => 'Acme', 'type' => 'organization', 'email' => 'info@acme.example', 'phone' => '+31 20 123'];
		$provision = static fn (array $fields, string $type = 'client'): array => ['provisionContactFromForm', ['form' => $fields, 'objectType' => $type]];

		return [
			'sync client' => ['world' => $world, 'call' => $sync('client')],
			'sync contact' => ['world' => $world, 'call' => $sync('contact', 'o-2')],
			'sync, contacts disabled' => ['world' => [...$world, 'enabled' => false], 'call' => $sync('client')],
			'sync, unknown type' => ['world' => $world, 'call' => $sync('lead')],
			'sync, type with a key in it' => ['world' => $world, 'call' => $sync('client_schema')],
			'sync, no register' => ['world' => [...$world, 'register' => ''], 'call' => $sync('client')],
			'sync, no schema' => ['world' => [...$world, 'schemas' => []], 'call' => $sync('contact')],
			'sync, not found' => ['world' => [...$world, 'found' => null], 'call' => $sync('client')],
			'sync, find throws' => ['world' => [...$world, 'found' => 'throw'], 'call' => $sync('client')],
			'sync, writer fails' => ['world' => [...$world, 'written' => null], 'call' => $sync('client')],
			'provision, new organisation' => ['world' => $world, 'call' => $provision($form)],
			'provision, email match' => ['world' => [...$world, 'hits' => ['info@acme.example' => [['UID' => ''], ['UID' => 'uid-mail']]]], 'call' => $provision($form, 'contact')],
			'provision, org match' => ['world' => [...$world, 'hits' => ['Acme' => [['UID' => 'uid-org']]]], 'call' => $provision($form)],
			'provision, search throws' => ['world' => [...$world, 'hits' => ['info@acme.example' => 'throw']], 'call' => $provision($form)],
			'provision, person without email' => ['world' => $world, 'call' => $provision(['name' => 'Jan', 'type' => 'person'])],
			'provision, unknown type' => ['world' => $world, 'call' => $provision($form, 'lead')],
			'provision, contacts disabled' => ['world' => [...$world, 'enabled' => false], 'call' => $provision($form)],
			'provision, writer fails' => ['world' => [...$world, 'written' => ''], 'call' => $provision($form)],
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
		$service = $this->build(class: ContactVcardService::class, doubles: $this->doubles(world: $case['world']));
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
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('isEnabled')->willReturnCallback(
			fn (): bool => $this->record(call: 'contacts.isEnabled', args: [], result: $world['enabled'])
		);
		$contacts->method('search')->willReturnCallback(
			function (mixed ...$args) use ($world): array {
				$this->record(call: 'contacts.search', args: $this->passed(class: IContactsManager::class, method: 'search', args: $args), result: null);
				$hits = ($world['hits'][$args[0]] ?? []);
				if ($hits === 'throw') {
					throw new RuntimeException('search down');
				}

				return $hits;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (mixed ...$args): string => $this->record(
				call: 'appConfig.getValueString',
				args: $this->passed(class: IAppConfig::class, method: 'getValueString', args: $args),
				result: (string)($world['schemas'][$args[1]] ?? $args[2])
			)
		);

		$resolver = $this->createMock(RegisterResolverService::class);
		$resolver->method('resolve')->willReturnCallback(
			fn (mixed ...$args): string => $this->record(call: 'registers.resolve', args: $this->passed(class: RegisterResolverService::class, method: 'resolve', args: $args), result: $world['register'])
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($world): ?ObjectEntityInterface {
				$this->record(call: 'objects.find', args: $this->passed(class: ObjectServiceInterface::class, method: 'find', args: $args), result: null);
				if ($world['found'] === 'throw') {
					throw new RuntimeException('register down');
				}

				if ($world['found'] === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($world['found']);

				return $entity;
			}
		);

		$builder = $this->createMock(ContactVcardPropertyBuilder::class);
		$builder->method('buildProperties')->willReturnCallback(
			fn (array $objData, string $objectType): array => $this->record(call: 'builder.buildProperties', args: [$objData, $objectType], result: ['FN' => (string)($objData['name'] ?? ''), 'KIND' => $objectType])
		);

		$writer = $this->createMock(ContactVcardWriterService::class);
		$writer->method('writeToAddressBook')->willReturnCallback(
			fn (array $properties, array $objData, string $objectType): ?string => $this->record(call: 'writer.writeToAddressBook', args: [$properties, $objData, $objectType], result: $world['written'])
		);
		$writer->method('writeVcard')->willReturnCallback(
			fn (mixed ...$args): ?string => $this->record(call: 'writer.writeVcard', args: $this->passed(class: ContactVcardWriterService::class, method: 'writeVcard', args: $args), result: $world['written'])
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
			IContactsManager::class => $contacts,
			IAppConfig::class => $appConfig,
			RegisterResolverService::class => $resolver,
			ObjectServiceInterface::class => $objects,
			ContactVcardPropertyBuilder::class => $builder,
			ContactVcardWriterService::class => $writer,
			LoggerInterface::class => $logger,
		];
	}//end doubles()

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
