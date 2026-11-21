<?php

/**
 * Pipelinq EmailLeaf.
 *
 * The OpenRegister email leaf and the register it links in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The OpenRegister EmailLinkService and the Pipelinq register its links are scoped to.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class EmailLeaf {
	/**
	 * Constructor.
	 *
	 * @param object $linkService OR EmailLinkService instance.
	 * @param string $register    The configured Pipelinq register (never empty).
	 */
	public function __construct(
		public readonly object $linkService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
