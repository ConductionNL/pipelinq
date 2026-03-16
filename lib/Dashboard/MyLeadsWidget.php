<?php

/**
 * My Leads dashboard widget.
 *
 * Shows leads assigned to the current user with overdue highlighting,
 * priority, and expected close date.
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
use OCP\IURLGenerator;
use OCP\Util;

/**
 * My Leads widget for the Nextcloud Dashboard.
 */
class MyLeadsWidget implements IWidget
{


    /**
     * Constructor.
     *
     * @param IL10N         $l10n         Localisation service
     * @param IURLGenerator $urlGenerator URL generator
     */
    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $urlGenerator
    ) {

    }//end __construct()


    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'pipelinq_my_leads_widget';

    }//end getId()


    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Mijn leads');

    }//end getTitle()


    /**
     * @inheritDoc
     */
    public function getOrder(): int
    {
        return 11;

    }//end getOrder()


    /**
     * @inheritDoc
     */
    public function getIconClass(): string
    {
        return 'icon-pipelinq-widget';

    }//end getIconClass()


    /**
     * @inheritDoc
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()


    /**
     * @inheritDoc
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID . '-myLeadsWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()


}//end class
