<?php

/**
 * Pipelinq CtiVendor.
 *
 * Names a CTI platform for its call results and log lines.
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
 * The platform identifier and display name of one CTI vendor.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class CtiVendor {
	/**
	 * Constructor.
	 *
	 * @param string $platform The platform identifier carried on call results.
	 * @param string $label    The display name used in log lines and errors.
	 */
	public function __construct(
		public readonly string $platform,
		public readonly string $label,
	) {
	}//end __construct()
}//end class
