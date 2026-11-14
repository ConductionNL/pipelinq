<?php

/**
 * Pipelinq WebhookFields.
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

/**
 * Optional field reader over one decoded CTI webhook payload (or a nested part of it).
 *
 * A field that is absent or null reads as null; any other value is cast.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */
final class WebhookFields {
	/**
	 * Constructor.
	 *
	 * @param array<array-key,mixed> $source The payload (or a nested part of it).
	 */
	public function __construct(
		private readonly array $source,
	) {
	}//end __construct()

	/**
	 * The field as a string, or null when it is absent or null.
	 *
	 * @param string $key The field name.
	 *
	 * @return string|null The field's string value.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function text(string $key): ?string {
		if (isset($this->source[$key]) === false) {
			return null;
		}

		return (string)$this->source[$key];
	}//end text()

	/**
	 * The field as an integer, or null when it is absent or null.
	 *
	 * @param string $key The field name.
	 *
	 * @return int|null The field's integer value.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function number(string $key): ?int {
		if (isset($this->source[$key]) === false) {
			return null;
		}

		return (int)$this->source[$key];
	}//end number()

	/**
	 * A nested part of the payload; absent or null reads as an empty part.
	 *
	 * @param string $key The field holding the nested part.
	 *
	 * @return self The nested part's fields.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function part(string $key): self {
		return new self(source: (array)($this->source[$key] ?? []));
	}//end part()

	/**
	 * The first entry of a list field; an absent list or entry reads as an empty part.
	 *
	 * @param string $key The field holding the list.
	 *
	 * @return self The first entry's fields.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function first(string $key): self {
		$entry = ((array)($this->source[$key] ?? []))[0] ?? [];
		return new self(source: (array)$entry);
	}//end first()
}//end class
