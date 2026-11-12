<?php

/**
 * Flat views of a find() call, for the recordings of the golden tests.
 *
 * Since openregister #9116, ObjectService::find() takes (id, ?ObjectFindOptions,
 * _rbac, _multitenancy). The golden recordings were taken against the flat
 * nine-parameter list. Spreading the options back into that list keeps each
 * recording a description of the read, not of the call shape. Mirrors
 * openregister's own tests/Unit/Support/FindCallShape.php.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Flat views of a find() call.
 */
final class FindCallShape {
	/**
	 * The flat parameter names, in the order find() declared them before #9116.
	 *
	 * @var array<int, string>
	 */
	public const NAMES = ['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit'];

	/**
	 * The defaults of that flat list; the id has none.
	 *
	 * @var array<int, mixed>
	 */
	private const DEFAULTS = [null, [], false, null, null, true, true, true, true];

	/**
	 * The arguments a find() double received, as the flat nine-value list.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> Id, extend, files, register, schema, rbac, multitenancy, render, audit.
	 */
	public static function find(array $args): array {
		$options = ($args[1] ?? null);
		if ($options instanceof ObjectFindOptions === false) {
			$options = new ObjectFindOptions();
		}

		return [
			($args[0] ?? null),
			$options->extend,
			$options->files,
			$options->register,
			$options->schema,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->render,
			$options->audit,
		];
	}//end find()

	/**
	 * The flat list with the trailing values that equal their default left off.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> The flat list, trimmed.
	 */
	public static function trimmed(array $args): array {
		$flat = self::find(args: $args);
		while (count($flat) > 1 && end($flat) === self::DEFAULTS[(count($flat) - 1)]) {
			array_pop($flat);
		}

		return $flat;
	}//end trimmed()

	/**
	 * The flat list keyed by parameter name, values at their default left out.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<string, mixed> Parameter name => value.
	 */
	public static function named(array $args): array {
		$named = [];
		foreach (self::find(args: $args) as $i => $value) {
			if ($i > 0 && $value === self::DEFAULTS[$i]) {
				continue;
			}

			$named[self::NAMES[$i]] = $value;
		}

		return $named;
	}//end named()
}//end class
