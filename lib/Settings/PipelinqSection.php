<?php

/**
 * Pipelinq Admin Section
 *
 * @category Settings
 * @package  OCA\Pipelinq\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin settings section for Pipelinq.
 */
class PipelinqSection implements IIconSection
{

    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
    ) {

    }//end __construct()


    public function getID(): string
    {
        return 'pipelinq';

    }//end getID()


    public function getName(): string
    {
        return $this->l10n->t('Pipelinq');

    }//end getName()


    public function getPriority(): int
    {
        return 80;

    }//end getPriority()


    public function getIconSvg(): string
    {
        return '';

    }//end getIconSvg()


}//end class
