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
