<?php

/**
 * Builds a class the way Nextcloud's DI container autowires it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Resolves each constructor parameter by its type from the given doubles, and
 * builds any other concrete Pipelinq class it meets the same way. A golden
 * test that builds its subject through this class keeps working when the
 * subject's collaborators are regrouped, because only the leaf doubles are
 * named.
 */
class Autowire {
	/**
	 * Contract => implementation, read once from Service\ContractBindings.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $bindings = null;

	/**
	 * Build a class from leaf doubles.
	 *
	 * @param string               $class   The class to build.
	 * @param array<string, mixed> $doubles Type (or parameter name) => value.
	 *
	 * @return object
	 */
	public static function build(string $class, array $doubles): object {
		$reflection = new ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if ($constructor === null) {
			return $reflection->newInstance();
		}

		$arguments = [];
		foreach ($constructor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = ($type instanceof ReflectionNamedType ? $type->getName() : '');
			if (array_key_exists($param->getName(), $doubles) === true) {
				$arguments[] = $doubles[$param->getName()];
			} elseif (array_key_exists($typeName, $doubles) === true) {
				$arguments[] = $doubles[$typeName];
			} elseif (self::implementationKey(typeName: $typeName, doubles: $doubles) !== null) {
				$arguments[] = $doubles[self::implementationKey(typeName: $typeName, doubles: $doubles)];
			} elseif (str_starts_with($typeName, 'OCA\\Pipelinq\\') === true && (new ReflectionClass($typeName))->isInstantiable() === true) {
				$arguments[] = self::build(class: $typeName, doubles: $doubles);
			} elseif (self::boundImplementation(contract: $typeName) !== null) {
				$arguments[] = self::build(class: self::boundImplementation(contract: $typeName), doubles: $doubles);
			} elseif ($param->isDefaultValueAvailable() === true) {
				$arguments[] = $param->getDefaultValue();
			} else {
				throw new LogicException('No double for '.$class.'::$'.$param->getName().' ('.$typeName.')');
			}
		}

		return $reflection->newInstanceArgs($arguments);
	}//end build()

	/**
	 * The double keyed by a class that implements the requested contract, so a
	 * double named by its implementation also answers for the contract.
	 *
	 * @param string               $typeName The parameter's type.
	 * @param array<string, mixed> $doubles  Type (or parameter name) => value.
	 *
	 * @return string|null The matching key, or null when none implements it.
	 */
	/**
	 * The class Service\ContractBindings binds a Kernel contract to, so a
	 * contract-typed collaborator without a double is built the way the
	 * container would build it.
	 *
	 * @param string $contract The contract (interface) name.
	 *
	 * @return string|null The implementation, or null when the contract is not bound.
	 */
	private static function boundImplementation(string $contract): ?string {
		if (self::$bindings === null) {
			self::$bindings = [];
			$source = (string)file_get_contents(dirname(__DIR__, 3).'/lib/Service/ContractBindings.php');
			$uses = [];
			preg_match_all('/^use ([\\w\\\\]+);/m', $source, $useMatches);
			foreach ($useMatches[1] as $fqcn) {
				$uses[substr((string)strrchr('\\'.$fqcn, '\\'), 1)] = $fqcn;
			}

			preg_match_all('/registerServiceAlias\((\w+)::class, (\w+)::class\)/', $source, $pairs, PREG_SET_ORDER);
			foreach ($pairs as $pair) {
				self::$bindings[$uses[$pair[1]] ?? ''] = $uses[$pair[2]] ?? 'OCA\\Pipelinq\\Service\\'.$pair[2];
			}
		}

		return self::$bindings[$contract] ?? null;
	}//end boundImplementation()

	private static function implementationKey(string $typeName, array $doubles): ?string {
		if ($typeName === '' || interface_exists($typeName) === false) {
			return null;
		}

		foreach (array_keys($doubles) as $key) {
			if (class_exists($key) === true && is_subclass_of($key, $typeName) === true) {
				return $key;
			}
		}

		return null;
	}//end implementationKey()
}//end class
