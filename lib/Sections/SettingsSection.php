<?php

/**
 * Pipelinq SettingsSection.
 *
 * Settings section for the Pipelinq admin settings page.
 *
 * @category Sections
 * @package  OCA\Pipelinq\Sections
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin settings section for Pipelinq.
 */
class SettingsSection implements IIconSection
{
    /**
     * Constructor.
     *
     * @param IL10N         $l            The localization service.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the section ID.
     *
     * @return string The section ID.
     */
    public function getID(): string
    {
        return 'pipelinq';
    }//end getID()

    /**
     * Get the section name.
     *
     * @return string The section name.
     */
    public function getName(): string
    {
        return $this->l->t('Pipelinq');
    }//end getName()

    /**
     * Get the section priority.
     *
     * @return int The section priority.
     */
    public function getPriority(): int
    {
        return 76;
    }//end getPriority()

    /**
     * Get the section icon URL.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(app: 'pipelinq', image: 'app.svg');
    }//end getIcon()
}//end class
