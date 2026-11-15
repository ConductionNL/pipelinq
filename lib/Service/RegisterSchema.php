<?php

/**
 * Pipelinq RegisterSchema.
 *
 * The OpenRegister register and schema an object is read from or written to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude internal value pair of a register id and a schema id, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A register id and a schema id that belong together.
 *
 * @spec exclude internal value pair of a register id and a schema id, no behaviour of its own
 */
final class RegisterSchema {
	/**
	 * Constructor.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
