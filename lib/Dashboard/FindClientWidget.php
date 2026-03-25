<?php

/**
 * Find Client dashboard widget.
 *
 * Enhanced client search widget with action buttons for quick CRM operations.
 * Replaces the original ClientSearchWidget.
 *
 * @category Dashboard
 * @package  OCA\Pipelinq\Dashboard
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
 * Find Client widget for the Nextcloud Dashboard.
 */
class FindClientWidget implements IWidget
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
        return 'pipelinq_find_client_widget';
    }//end getId()

    /**
     * Get the translated widget title.
     *
     * @return string The widget title
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Find Client');
    }//end getTitle()

    /**
     * Get the display order of this widget.
     *
     * @return int The sort order
     */
    public function getOrder(): int
    {
        return 13;
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
        Util::addScript(Application::APP_ID, Application::APP_ID.'-findClientWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');
    }//end load()
}//end class
