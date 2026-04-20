<?php

/**
 * Recent Activities dashboard widget.
 *
 * Shows recently modified leads and requests sorted by modification date.
 *
 * @category Dashboard
 * @package  OCA\Pipelinq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

namespace OCA\Pipelinq\Dashboard;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

/**
 * Recent Activities widget for the Nextcloud Dashboard.
 */
class RecentActivitiesWidget implements IWidget
{
    /**
     * Constructor.
     *
     * @param IL10N $l10n Localisation service
     */
    public function __construct(
        private IL10N $l10n,
    ) {

    }//end __construct()

    /**
     * Get the unique widget identifier.
     *
     * @return string The widget ID
     */
    public function getId(): string
    {
        return 'pipelinq_recent_activities_widget';

    }//end getId()

    /**
     * Get the translated widget title.
     *
     * @return string The widget title
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Recent Activities');

    }//end getTitle()

    /**
     * Get the display order of this widget.
     *
     * @return int The sort order
     */
    public function getOrder(): int
    {
        return 12;

    }//end getOrder()

    /**
     * Get the CSS class for the widget icon.
     *
     * @return string The icon CSS class
     */
    public function getIconClass(): string
    {
        return 'icon-pipelinq-widget';

    }//end getIconClass()

    /**
     * Get the URL for the widget header link.
     *
     * @return string|null The URL or null if none
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()

    /**
     * Load the widget scripts and styles.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID.'-recentActivitiesWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()
}//end class
