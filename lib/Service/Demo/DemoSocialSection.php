<?php

/**
 * Pipelinq DemoSocialSection.
 *
 * One part of the seed file's `social` section and the schema its rows live in.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

/**
 * The definitions of one social seed part (accounts, posts or publications) and their schema.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
final class DemoSocialSection {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $definitions The seed definitions.
	 * @param string                           $schemaSlug  The schema their rows live in.
	 */
	public function __construct(
		public readonly array $definitions,
		public readonly string $schemaSlug,
	) {
	}//end __construct()
}//end class
