<?php

/**
 * Pipelinq ShardTable.
 *
 * One OpenRegister shard table and the columns it holds, while a repair step migrates it.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

/**
 * A shard table's name and the column names it held when the migration read it.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class ShardTable {
	/**
	 * Constructor.
	 *
	 * @param string             $name    The table name.
	 * @param array<int, string> $columns The column names it holds.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $columns,
	) {
	}//end __construct()
}//end class
