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
}//end class
