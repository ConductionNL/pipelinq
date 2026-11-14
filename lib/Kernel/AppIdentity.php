<?php

/**
 * The app's identity, without the bootstrap.
 *
 * Code that only needs the app id reads it here, so it does not depend on
 * the Application bootstrap class.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel;

/**
 * The app id as one constant.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel
 *
 * @spec exclude Structural identity constant, no functional requirement.
 */
final class AppIdentity {

	/**
	 * The Nextcloud app id.
	 *
	 * @var string
	 */
	public const APP_ID = 'pipelinq';

}//end class
