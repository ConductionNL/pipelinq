<?php

/**
 * Pipelinq SystemTagKind.
 *
 * One kind of system tag an admin controller manages.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

/**
 * The object type of a kind of system tag, and the name of the controller
 * that manages it, for its failure log.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
final class SystemTagKind {
	/**
	 * Constructor.
	 *
	 * @param string $objectType The system tag object type.
	 * @param string $logLabel   The controller name failures are logged under.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly string $logLabel,
	) {
	}//end __construct()
}//end class
