<?php

/**
 * Pipelinq UplListEntry.
 *
 * One parsed row of the UPL list next to the stored entry with the same URI.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Upl;

/**
 * A parsed UPL row and the stored entry it would create or update.
 *
 * @spec openspec/changes/product-service-catalogue-public/tasks.md
 */
final class UplListEntry {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $row      The parsed row.
	 * @param array<string, mixed>|null $existing The stored entry with the same URI, or null.
	 */
	public function __construct(
		public readonly array $row,
		public readonly ?array $existing,
	) {
	}//end __construct()

	/**
	 * Whether the stored entry already carries the row's name and audience.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/product-service-catalogue-public/tasks.md
	 */
	public function isUnchanged(): bool {
		return ($this->existing !== null && $this->existing['name'] === $this->row['name'] && $this->existing['audience'] === $this->row['audience']);
	}//end isUnchanged()
}//end class
