<?php

/**
 * Pipelinq BrpConfigController.
 *
 * Admin-gated configuration surface for the HaalCentraal BRP integration:
 * OAuth2 endpoints + client id, the mTLS certificate/key/CA file paths, the
 * cache TTL and retention period, and the authorised role groups. The OAuth2
 * client secret and the webhook secret are stored ENCRYPTED-at-rest via
 * {@see ICrypto} and never returned to the client (ADR-005); the response only
 * reports whether each secret is present.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BrpMonitorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Admin controller for the BRP integration settings (REQ-BSN-003 / 004 / 008).
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#4.1
 */
class BrpConfigController extends Controller
{
    /**
     * Plain (non-secret) string config keys exposed to the admin form.
     *
     * @var array<string, string>
     */
    private const PLAIN_KEYS = [
        'brp.oauth_endpoint'         => '',
        'brp.personen_endpoint'      => '',
        'brp.client_id'              => '',
        'brp.cert_path'              => '',
        'brp.key_path'               => '',
        'brp.ca_bundle'              => '',
        'brp.cache_ttl_hours'        => '24',
        'brp.retention_days'         => '7',
        'brp.health_check_timezone'  => 'UTC',
        'brp.role_group_burgerzaken' => 'behandelaar-burgerzaken',
        'brp.role_group_avg'         => 'behandelaar-avg',
    ];

    /**
     * Constructor.
     *
     * @param IRequest          $request   The request.
     * @param IAppConfig        $appConfig The app config.
     * @param ICrypto           $crypto    The authenticated-encryption primitive.
     * @param ISecureRandom     $random    Secure random for webhook-secret generation.
     * @param BrpMonitorService $monitor   The audit-trail aggregation service.
     */
    public function __construct(
        IRequest $request,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private ISecureRandom $random,
        private BrpMonitorService $monitor,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the current BRP configuration (no secrets, only presence flags).
     *
     * @return JSONResponse The configuration.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#4.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function show(): JSONResponse
    {
        $config = [];
        foreach (self::PLAIN_KEYS as $key => $default) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
        }

        $config['brp.client_secret_set']  = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret', '') !== '';
        $config['brp.webhook_secret_set'] = $this->appConfig->getValueString(Application::APP_ID, 'brp.webhook_secret', '') !== '';

        return new JSONResponse($config);
    }//end show()

    /**
     * Persist the BRP configuration. Secrets are encrypted at rest.
     *
     * @return JSONResponse The updated configuration (presence flags only).
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#4.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(): JSONResponse
    {
        foreach (array_keys(self::PLAIN_KEYS) as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $value);
            }
        }

        $clientSecret = (string) $this->request->getParam('brp.client_secret', '');
        if ($clientSecret !== '') {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'brp.client_secret',
                $this->crypto->encrypt($clientSecret),
                false,
                true
            );
        }

        // The webhook secret is auto-generated on demand; an explicit reset
        // regenerates it without ever exposing the value to the client.
        if ((bool) $this->request->getParam('brp.reset_webhook_secret', false) === true
            || $this->appConfig->getValueString(Application::APP_ID, 'brp.webhook_secret', '') === ''
        ) {
            $this->appConfig->setValueString(
                Application::APP_ID,
                'brp.webhook_secret',
                $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC),
                false,
                true
            );
        }

        return $this->show();
    }//end update()

    /**
     * Return the 24-hour BRP service-health report for the admin monitor tile.
     *
     * Aggregates the immutable, already-masked audit trail (lookups, cache-hit
     * ratio, error rate, average response time, refusals — REQ-BSN-010) and the
     * mTLS certificate expiry derived from the locally configured certificate
     * file. No BSN, secret or live BRP call is involved; the certificate is read
     * from disk only (no private key access required).
     *
     * @return JSONResponse The aggregated report plus certificate status.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#6.1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function report(): JSONResponse
    {
        $since  = gmdate('Y-m-d\TH:i:s\Z', (time() - 86400));
        $report = $this->monitor->report($since);

        $report['since']       = $since;
        $report['certificate'] = $this->certificateStatus();

        return new JSONResponse($report);
    }//end report()

    /**
     * Inspect the configured mTLS certificate file for its expiry date.
     *
     * @return array<string, mixed> Certificate status: configured, validTo,
     *                              daysRemaining and a coarse status band.
     */
    private function certificateStatus(): array
    {
        $certPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '');
        if ($certPath === '' || is_readable($certPath) === false) {
            return ['configured' => false];
        }

        $pem = file_get_contents($certPath);
        if ($pem === false) {
            return ['configured' => false];
        }

        $parsed = openssl_x509_parse($pem);
        if (is_array($parsed) === false || isset($parsed['validTo_time_t']) === false) {
            return ['configured' => true, 'parsable' => false];
        }

        $validTo       = (int) $parsed['validTo_time_t'];
        $daysRemaining = (int) floor(($validTo - time()) / 86400);

        $status = 'ok';
        if ($daysRemaining <= 7) {
            $status = 'critical';
        } else if ($daysRemaining <= 30) {
            $status = 'warning';
        }

        return [
            'configured'    => true,
            'parsable'      => true,
            'validTo'       => gmdate('Y-m-d', $validTo),
            'daysRemaining' => $daysRemaining,
            'status'        => $status,
        ];
    }//end certificateStatus()
}//end class
