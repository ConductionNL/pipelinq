<?php

/**
 * Pipelinq SlaAdminController.
 *
 * Admin configuration for the SLA engine: sweep interval, business-hours
 * window, default and custom holiday calendars (REQ-008, REQ-010).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Admin-only SLA engine configuration (REQ-008, REQ-010).
 *
 * All endpoints are gated by {@see AuthorizedAdminSetting} so only an
 * administrator of the Pipelinq settings can read or change the sweep cadence,
 * business-hours window, and holiday calendars. Input is validated server-side.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
 */
class SlaAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest   $request   The request.
     * @param IAppConfig $appConfig The app configuration.
     * @param IL10N      $l10n      The localization service.
     */
    public function __construct(
        IRequest $request,
        private IAppConfig $appConfig,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the current SLA engine configuration.
     *
     * @return JSONResponse The configuration.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function getConfig(): JSONResponse
    {
        $app        = Application::APP_ID;
        $defaultCal = 'nl-feestdagen-rijksoverheid';
        return new JSONResponse(
            [
                'sweepJobInterval'          => $this->appConfig->getValueInt($app, 'sla_sweep_interval', 300),
                'defaultBusinessHoursStart' => $this->appConfig->getValueString($app, BusinessHoursCalculator::CONFIG_START, '09:00'),
                'defaultBusinessHoursEnd'   => $this->appConfig->getValueString($app, BusinessHoursCalculator::CONFIG_END, '17:00'),
                'defaultHolidayCalendar'    => $this->appConfig->getValueString($app, 'sla_default_holiday_calendar', $defaultCal),
                'customHolidayOverrides'    => $this->appConfig->getValueString($app, HolidayCalendarService::OVERRIDES_KEY, ''),
                'trackedTypes'              => $this->appConfig->getValueString($app, 'sla_tracked_types', 'request,complaint'),
            ]
        );
    }//end getConfig()

    /**
     * Update the SLA engine configuration with server-side validation.
     *
     * @return JSONResponse The saved configuration or a validation error.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-008
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function setConfig(): JSONResponse
    {
        $interval = (int) $this->request->getParam('sweepJobInterval', 300);
        if ($interval < 60 || $interval > 1800) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Sweep interval must be between 60 and 1800 seconds')],
                Http::STATUS_BAD_REQUEST
            );
        }

        $start = (string) $this->request->getParam('defaultBusinessHoursStart', '09:00');
        $end   = (string) $this->request->getParam('defaultBusinessHoursEnd', '17:00');
        if ($this->isValidTime(time: $start) === false || $this->isValidTime(time: $end) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Business hours must be valid HH:MM times')],
                Http::STATUS_BAD_REQUEST
            );
        }

        $overrides = (string) $this->request->getParam('customHolidayOverrides', '');
        if ($overrides !== '' && json_decode($overrides) === null && json_last_error() !== JSON_ERROR_NONE) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Custom holiday overrides must be valid JSON')],
                Http::STATUS_BAD_REQUEST
            );
        }

        $this->appConfig->setValueInt(Application::APP_ID, 'sla_sweep_interval', $interval);
        $this->appConfig->setValueString(Application::APP_ID, BusinessHoursCalculator::CONFIG_START, $start);
        $this->appConfig->setValueString(Application::APP_ID, BusinessHoursCalculator::CONFIG_END, $end);
        $this->appConfig->setValueString(
            Application::APP_ID,
            'sla_default_holiday_calendar',
            (string) $this->request->getParam('defaultHolidayCalendar', 'nl-feestdagen-rijksoverheid')
        );
        $this->appConfig->setValueString(Application::APP_ID, HolidayCalendarService::OVERRIDES_KEY, $overrides);

        return $this->getConfig();
    }//end setConfig()

    /**
     * Validate an HH:MM time string.
     *
     * @param string $time The time string.
     *
     * @return bool True when valid.
     */
    private function isValidTime(string $time): bool
    {
        return (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1);
    }//end isValidTime()
}//end class
