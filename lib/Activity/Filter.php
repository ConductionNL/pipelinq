<?php

/**
 * Pipelinq Activity Filter.
 *
 * Filter for Pipelinq activity events in the activity stream.
 *
 * @category Activity
 * @package  OCA\Pipelinq\Activity
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

namespace OCA\Pipelinq\Activity;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * Activity filter for Pipelinq events.
 */
class Filter implements IFilter
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
     * Get the unique identifier of the filter.
     *
     * @return string The filter identifier.
     */
    public function getIdentifier(): string
    {
        return Application::APP_ID;
    }//end getIdentifier()

    /**
     * Get the human-readable name of the filter.
     *
     * @return string The filter name.
     */
    public function getName(): string
    {
        return $this->l->t('Pipelinq');
    }//end getName()

    /**
     * Get the priority of the filter.
     *
     * @return int The filter priority.
     */
    public function getPriority(): int
    {
        return 50;
    }//end getPriority()

    /**
     * Get the icon URL for the filter.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(app: Application::APP_ID, image: 'app.svg')
        );
    }//end getIcon()

    /**
     * Filter the activity types to show.
     *
     * @param array $types The available types.
     *
     * @return array The filtered types.
     */
    public function filterTypes(array $types): array
    {
        return ['pipelinq_assignment', 'pipelinq_stage_status', 'pipelinq_notes'];
    }//end filterTypes()

    /**
     * Get the allowed apps for this filter.
     *
     * @return array The allowed app IDs.
     */
    public function allowedApps(): array
    {
        return [Application::APP_ID];
    }//end allowedApps()
}//end class
