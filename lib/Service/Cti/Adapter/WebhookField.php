<?php

/**
 * Pipelinq WebhookField.
 *
 * Reads optional scalar fields out of a decoded CTI webhook payload.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

/**
 * Optional field reader shared by the CTI adapters' webhook normalisation.
 *
 * A field that is absent or null reads as null; any other value is cast.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class WebhookField {
	/**
	 * The field as a string, or null when it is absent or null.
	 *
	 * @param array<array-key,mixed> $source The payload (or a nested part of it).
	 * @param string                 $key    The field name.
	 *
	 * @return string|null The field's string value.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public static function text(array $source, string $key): ?string {
		if (isset($source[$key]) === false) {
			return null;
		}

		return (string)$source[$key];
	}//end text()

	/**
	 * The field as an integer, or null when it is absent or null.
	 *
	 * @param array<array-key,mixed> $source The payload (or a nested part of it).
	 * @param string                 $key    The field name.
	 *
	 * @return int|null The field's integer value.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public static function number(array $source, string $key): ?int {
		if (isset($source[$key]) === false) {
			return null;
		}

		return (int)$source[$key];
	}//end number()

	/**
	 * A nested part of the payload as an array; absent or null reads as empty.
	 *
	 * @param array<array-key,mixed> $source The payload.
	 * @param string                 $key    The field holding the nested part.
	 *
	 * @return array<array-key,mixed> The nested part.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public static function part(array $source, string $key): array {
		return (array)($source[$key] ?? []);
	}//end part()
}//end class
