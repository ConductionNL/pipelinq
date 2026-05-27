<?php

/**
 * Pipelinq AutomationService.
 *
 * Service for managing CRM workflow automations stored as OpenRegister objects.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-47
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for CRM workflow automation management.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-1
 */
class AutomationService
{
    /**
     * Valid trigger types for CRM automations.
     */
    private const VALID_TRIGGERS = [
        'lead_created',
        'lead_stage_changed',
        'lead_assigned',
        'lead_value_changed',
        'contact_created',
        'request_created',
        'request_status_changed',
    ];

    /**
     * Valid action types for CRM automations.
     */
    private const VALID_ACTIONS = [
        'assign_lead',
        'move_stage',
        'send_notification',
        'update_field',
        'add_note',
        'webhook',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig   The app configuration.
     * @param IUserSession    $userSession The user session.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the list of valid trigger types.
     *
     * @return array The valid trigger types.
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-1
     */
    public function getValidTriggers(): array
    {
        return self::VALID_TRIGGERS;
    }//end getValidTriggers()

    /**
     * Get the list of valid action types.
     *
     * @return array The valid action types.
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-1
     */
    public function getValidActions(): array
    {
        return self::VALID_ACTIONS;
    }//end getValidActions()

    /**
     * Check if an automation matches a given trigger event and entity data.
     *
     * @param array  $automation The automation configuration.
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data to check conditions against.
     *
     * @return bool Whether the automation matches.
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-5
     */
    public function matchesConditions(array $automation, string $trigger, array $entityData): bool
    {
        if (($automation['isActive'] ?? false) !== true) {
            return false;
        }

        if (($automation['trigger'] ?? '') !== $trigger) {
            return false;
        }

        $conditions = $automation['triggerConditions'] ?? [];
        if (empty($conditions) === true) {
            return true;
        }

        return $this->evaluateConditions(conditions: $conditions, entityData: $entityData);
    }//end matchesConditions()

    /**
     * Evaluate trigger conditions against entity data.
     *
     * @param array $conditions The conditions to evaluate.
     * @param array $entityData The entity data.
     *
     * @return bool Whether all conditions are met.
     */
    private function evaluateConditions(array $conditions, array $entityData): bool
    {
        foreach ($conditions as $field => $expected) {
            $actual = $entityData[$field] ?? null;
            if ($actual === null) {
                return false;
            }

            if (is_array($expected) === true) {
                if (isset($expected['operator']) === true) {
                    $result = $this->evaluateOperator(
                        operator: $expected['operator'],
                        actual: $actual,
                        value: $expected['value'] ?? null
                    );
                    if ($result === false) {
                        return false;
                    }

                    continue;
                }

                if (in_array($actual, $expected, true) === false) {
                    return false;
                }

                continue;
            }

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }//end foreach

        return true;
    }//end evaluateConditions()

    /**
     * Evaluate a comparison operator.
     *
     * @param string $operator The operator (gt, gte, lt, lte, eq, neq).
     * @param mixed  $actual   The actual value.
     * @param mixed  $value    The expected value.
     *
     * @return bool Whether the comparison passes.
     */
    private function evaluateOperator(string $operator, mixed $actual, mixed $value): bool
    {
        return match ($operator) {
            'gt'    => $actual > $value,
            'gte'   => $actual >= $value,
            'lt'    => $actual < $value,
            'lte'   => $actual <= $value,
            'eq'    => $actual == $value,
            'neq'   => $actual != $value,
            default => false,
        };
    }//end evaluateOperator()

    /**
     * Build a webhook payload for an automation trigger.
     *
     * @param array  $automation The automation configuration.
     * @param string $trigger    The trigger event type.
     * @param array  $entityData The entity data.
     *
     * @return array The webhook payload.
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-3
     */
    public function buildWebhookPayload(array $automation, string $trigger, array $entityData): array
    {
        return [
            'automationId'   => $automation['id'] ?? '',
            'automationName' => $automation['name'] ?? '',
            'trigger'        => $trigger,
            'entity'         => $entityData,
            'timestamp'      => (new \DateTime())->format('c'),
            'actions'        => $automation['actions'] ?? [],
        ];
    }//end buildWebhookPayload()

    /**
     * Execute a webhook action by sending entity data to the configured URL.
     *
     * The URL is validated against an SSRF allow-list before firing:
     * - Scheme must be http or https.
     * - Resolved IP must not be loopback (127.x, ::1), link-local (169.254.x),
     *   RFC-1918 private (10.x, 172.16-31.x, 192.168.x), or ULA IPv6 (fc00::/7).
     * - Response body is never returned to the caller (SSRF exfiltration guard).
     *
     * @param string $webhookUrl The target webhook URL.
     * @param array  $payload    The payload to send.
     *
     * @return array The execution result with status (no response body exposed).
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-4
     */
    public function fireWebhook(string $webhookUrl, array $payload): array
    {
        if (empty($webhookUrl) === true) {
            return ['status' => 'skipped', 'reason' => 'No webhook URL configured'];
        }

        $ssrfCheck = $this->validateWebhookUrl(webhookUrl: $webhookUrl);
        if ($ssrfCheck !== null) {
            $this->logger->warning('Automation webhook blocked (SSRF guard): '.$ssrfCheck, ['url' => $webhookUrl]);
            return ['status' => 'blocked', 'reason' => $ssrfCheck];
        }

        try {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            // Disable redirects to prevent SSRF via redirect chains.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            // Cap response body to prevent memory exhaustion.
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);

            $rawResponse = curl_exec($ch);
            $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $status = 'success';
            } else {
                $status = 'failure';
            }

            // Never return raw response body to the caller (SSRF exfiltration guard).
            return [
                'status'   => $status,
                'httpCode' => $httpCode,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Automation webhook failed: '.$e->getMessage());
            return [
                'status' => 'failure',
                'error'  => $e->getMessage(),
            ];
        }//end try
    }//end fireWebhook()

    /**
     * Validate a webhook URL against the SSRF allow-list.
     *
     * Returns a string describing the rejection reason, or null when the URL is safe.
     *
     * @param string $webhookUrl The URL to validate.
     *
     * @return string|null Rejection reason or null when allowed.
     * @spec   openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-4
     */
    public function validateWebhookUrl(string $webhookUrl): ?string
    {
        $parsed = parse_url($webhookUrl);
        if ($parsed === false || empty($parsed['scheme']) === true || empty($parsed['host']) === true) {
            return 'Malformed URL';
        }

        $scheme = strtolower($parsed['scheme']);
        if (in_array($scheme, ['http', 'https'], true) === false) {
            return 'Only http and https schemes are allowed';
        }

        $host = $parsed['host'];

        // Resolve the hostname to an IP.
        $ip = gethostbyname($host);

        // Gethostbyname returns the original string on failure.
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return 'Could not resolve hostname';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->validateIpv4(ip: $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->validateIpv6(ip: $ip);
        }

        return null;
    }//end validateWebhookUrl()

    /**
     * Validate an IPv4 address against the SSRF block-list.
     *
     * @param string $ip The IPv4 address.
     *
     * @return string|null Rejection reason or null when allowed.
     */
    private function validateIpv4(string $ip): ?string
    {
        $long = ip2long($ip);
        if ($long === false) {
            return 'Invalid IPv4 address';
        }

        // Loopback: 127.0.0.0/8.
        if (($long & 0xFF000000) === 0x7F000000) {
            return 'Loopback addresses are not allowed';
        }

        // Link-local: 169.254.0.0/16.
        if (($long & 0xFFFF0000) === 0xA9FE0000) {
            return 'Link-local addresses are not allowed';
        }

        // RFC-1918: 10.0.0.0/8.
        if (($long & 0xFF000000) === 0x0A000000) {
            return 'Private (RFC-1918) addresses are not allowed';
        }

        // RFC-1918: 172.16.0.0/12.
        if (($long & 0xFFF00000) === 0xAC100000) {
            return 'Private (RFC-1918) addresses are not allowed';
        }

        // RFC-1918: 192.168.0.0/16.
        if (($long & 0xFFFF0000) === 0xC0A80000) {
            return 'Private (RFC-1918) addresses are not allowed';
        }

        return null;
    }//end validateIpv4()

    /**
     * Validate an IPv6 address against the SSRF block-list.
     *
     * @param string $ip The IPv6 address.
     *
     * @return string|null Rejection reason or null when allowed.
     */
    private function validateIpv6(string $ip): ?string
    {
        // Loopback: ::1.
        if ($ip === '::1') {
            return 'Loopback addresses are not allowed';
        }

        // ULA: fc00::/7 — first byte is 0xFC or 0xFD.
        $packed = inet_pton($ip);
        if ($packed !== false) {
            $firstByte = ord($packed[0]);
            if (($firstByte & 0xFE) === 0xFC) {
                return 'ULA (private) IPv6 addresses are not allowed';
            }

            // Link-local: fe80::/10.
            $firstTwoBits = ($firstByte & 0xC0);
            $secondBits   = (ord($packed[0]) & 0x3F);
            if ($firstByte === 0xFE && ($ord1 = ord($packed[1])) >= 0x80 && $ord1 <= 0xBF) {
                return 'Link-local IPv6 addresses are not allowed';
            }
        }

        return null;
    }//end validateIpv6()
}//end class
