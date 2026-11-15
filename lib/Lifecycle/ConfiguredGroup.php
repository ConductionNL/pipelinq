<?php

/**
 * Pipelinq ConfiguredGroup.
 *
 * The app-config setting that names a group, and its default.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

/**
 * The app-config key naming a group that grants access, and the group used when
 * the key is unset. An explicitly empty value grants nothing.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */
final class ConfiguredGroup {
	/**
	 * Constructor.
	 *
	 * @param string $key     The app-config key.
	 * @param string $default The group when the key is unset.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $default='',
	) {
	}//end __construct()
}//end class
