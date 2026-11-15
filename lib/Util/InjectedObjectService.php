<?php

/**
 * Pipelinq InjectedObjectService.
 *
 * The injected OpenRegister ObjectService.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * Hands out the ObjectService the host had injected. The host class provides
 * an `$objectService` property.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait InjectedObjectService {

	/**
	 * The OpenRegister ObjectService.
	 *
	 * @return object
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end trait
