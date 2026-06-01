<?php

/**
 * Pipelinq Admin Settings Registration
 *
 * @category Settings
 * @package  OCA\Pipelinq\Settings
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

namespace OCA\Pipelinq\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Admin settings page registration.
 */
class PipelinqAdmin implements ISettings
{

    public function getForm(): TemplateResponse
    {
        return new TemplateResponse('pipelinq', 'settings/admin', [], 'blank');

    }//end getForm()


    public function getSection(): string
    {
        return 'pipelinq';

    }//end getSection()


    public function getPriority(): int
    {
        return 0;

    }//end getPriority()


}//end class
