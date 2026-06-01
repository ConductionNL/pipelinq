<?php

/**
 * Pipelinq Application Bootstrap
 *
 * @category AppInfo
 * @package  OCA\Pipelinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Application entry point.
 */
class Application extends App implements IBootstrap
{

    public const APP_ID = 'pipelinq';


    public function __construct()
    {
        parent::__construct(self::APP_ID);

    }//end __construct()


    public function register(IRegistrationContext $context): void
    {

    }//end register()


    public function boot(IBootContext $context): void
    {

    }//end boot()


}//end class
