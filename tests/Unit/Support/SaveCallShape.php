<?php

/**
 * Flat views of a saveObject() call, for the recordings of the golden tests.
 *
 * Since openregister #9123, ObjectService::saveObject() takes (object,
 * ObjectSaveOptions, _rbac, _multitenancy). The golden recordings and the
 * doubles that index the arguments were written against the flat
 * fifteen-parameter list. Spreading the options back into that list keeps a
 * recording a description of the write, not of the call shape.
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

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Flat views of a saveObject() call.
 */
final class SaveCallShape {
	/**
	 * The flat parameter names, in the order saveObject() declared them before #9123.
	 *
	 * @var array<int, string>
	 */
	public const NAMES = [
		'object',
		'extend',
		'register',
		'schema',
		'uuid',
		'_rbac',
		'_multitenancy',
		'silent',
		'_validation',
		'uploadedFiles',
		'currentUser',
		'failIfExists',
		'_unowned',
		'_dedupOverride',
		'_owner',
	];

	/**
	 * The defaults of that flat list; the object has none.
	 *
	 * @var array<int, mixed>
	 */
	private const DEFAULTS = [null, [], null, null, null, true, true, false, true, null, null, false, false, false, null];

	/**
	 * The arguments a saveObject() double received, as the flat fifteen-value list.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> The flat list.
	 */
	public static function flat(array $args): array {
		$options = ($args[1] ?? null);
		if ($options instanceof ObjectSaveOptions === false) {
			$options = new ObjectSaveOptions();
		}

		return [
			($args[0] ?? null),
			$options->extend,
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
			$options->actor->unowned,
			$options->policy->dedupOverride,
			$options->actor->owner,
		];
	}//end flat()

	/**
	 * The flat list with the trailing values that equal their default left off.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> The flat list, trimmed.
	 */
	public static function trimmed(array $args): array {
		$flat = self::flat(args: $args);
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
		foreach (self::flat(args: $args) as $i => $value) {
			if ($i > 0 && $value === self::DEFAULTS[$i]) {
				continue;
			}

			$named[self::NAMES[$i]] = $value;
		}

		return $named;
	}//end named()
}//end class
