<?php

/**
 * Pipelinq IntakeFormService.
 *
 * Service for processing public intake form submissions.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-42
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-43
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-44
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-45
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-46
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for public intake form processing, spam protection, and entity creation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IntakeFormService
{
    /**
     * Maximum submissions per IP per form within the rate limit window.
     */
    private const RATE_LIMIT_MAX = 10;

    /**
     * Rate limit window in seconds (5 minutes).
     */
    private const RATE_LIMIT_WINDOW = 300;

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The app configuration.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if a submission is spam (honeypot field filled).
     *
     * @param array $submission The submitted data.
     *
     * @return bool True if the submission is detected as spam.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-45
     */
    public function isSpam(array $submission): bool
    {
        // Honeypot field: if '_hp_field' has a value, it's a bot.
        $honeypot = $submission['_hp_field'] ?? '';

        return $honeypot !== '';
    }//end isSpam()

    /**
     * Check rate limiting for form submissions from an IP.
     *
     * @param string $ip     The submitter's IP address.
     * @param string $formId The form ID.
     *
     * @return bool True if the rate limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-45
     */
    public function isRateLimited(string $ip, string $formId): bool
    {
        // Key on IP only so that cycling formId cannot bypass the per-IP budget.
        // The $formId parameter is retained in the signature for callers that may
        // log or trace it, but it is intentionally NOT included in the cache key.
        unset($formId);
        $key = 'pipelinq_intake_'.md5($ip);

        if (function_exists('apcu_fetch') === false) {
            return false;
        }

        $count = apcu_fetch($key);
        if ($count === false) {
            apcu_store($key, 1, self::RATE_LIMIT_WINDOW);
            return false;
        }

        if ($count >= self::RATE_LIMIT_MAX) {
            return true;
        }

        apcu_inc($key);
        return false;
    }//end isRateLimited()

    /**
     * Map submitted form data to entity properties using field mappings.
     *
     * @param array  $fieldMappings The field-to-property mappings.
     * @param array  $submission    The submitted data.
     * @param string $entityType    The target entity type ('contact' or 'lead').
     *
     * @return array Mapped entity data.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-46
     */
    public function mapToEntity(array $fieldMappings, array $submission, string $entityType): array
    {
        $mapped   = [];
        $unmapped = [];

        foreach ($submission as $fieldName => $value) {
            if (str_starts_with($fieldName, '_') === true) {
                continue;
            }

            $mapping = $fieldMappings[$fieldName] ?? null;
            if ($mapping !== null && isset($mapping['entity']) === true && $mapping['entity'] === $entityType) {
                $mapped[$mapping['property']] = $value;
            } else if ($mapping === null) {
                $unmapped[$fieldName] = $value;
            }
        }//end foreach

        if (empty($unmapped) === false && $entityType === 'lead') {
            $mapped['notes'] = json_encode($unmapped);
        }

        return $mapped;
    }//end mapToEntity()

    /**
     * Generate iframe embed code for a form.
     *
     * @param string $formId  The form ID.
     * @param string $baseUrl The Nextcloud base URL.
     *
     * @return string The iframe HTML snippet.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-43
     */
    public function generateIframeEmbed(string $formId, string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/').'/index.php/apps/pipelinq/api/public/forms/'.$formId;
        $src = htmlspecialchars($url);
        return '<iframe src="'.$src.'" width="100%" height="500" frameborder="0" style="border:none;"></iframe>';
    }//end generateIframeEmbed()

    /**
     * Generate JavaScript embed snippet for a form.
     *
     * @param string $formId  The form ID.
     * @param string $baseUrl The Nextcloud base URL.
     *
     * @return string The JavaScript embed snippet.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-43
     */
    public function generateJsEmbed(string $formId, string $baseUrl): string
    {
        $url    = rtrim($baseUrl, '/').'/index.php/apps/pipelinq/api/public/forms/'.$formId;
        $safeId = htmlspecialchars($formId);

        $js  = '<div id="pipelinq-form-'.$safeId.'"></div>'."\n";
        $js .= "<script>\n";
        $js .= "(function(){\n";
        $js .= "  var c=document.getElementById('pipelinq-form-".$safeId."');\n";
        $js .= "  var f=document.createElement('iframe');\n";
        $js .= "  f.src='".$url."';\n";
        $js .= "  f.style.cssText='width:100%;height:500px;border:none;';\n";
        $js .= "  c.appendChild(f);\n";
        $js .= "})();\n";
        $js .= '</script>';

        return $js;
    }//end generateJsEmbed()

    /**
     * Generate CSV content from submission records.
     *
     * @param array $submissions Array of submission objects.
     * @param array $fields      Form field definitions for column headers.
     *
     * @return string CSV content.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-44
     */
    public function exportCsv(array $submissions, array $fields): string
    {
        $headers = ['Submitted At', 'Status', 'Contact ID', 'Lead ID'];
        foreach ($fields as $field) {
            $headers[] = $field['label'] ?? $field['name'] ?? 'Unknown';
        }

        $rows = [implode(',', array_map([$this, 'neutralizeCsvCell'], $headers))];

        foreach ($submissions as $sub) {
            $row  = [
                $sub['submittedAt'] ?? '',
                $sub['status'] ?? '',
                $sub['contactId'] ?? '',
                $sub['leadId'] ?? '',
            ];
            $data = $sub['data'] ?? [];
            foreach ($fields as $field) {
                $name  = $field['name'] ?? '';
                $value = $data[$name] ?? '';
                $row[] = $value;
            }

            $rows[] = implode(',', array_map([$this, 'neutralizeCsvCell'], $row));
        }//end foreach

        return implode("\n", $rows);
    }//end exportCsv()

    /**
     * Neutralize a CSV cell value to prevent formula injection.
     *
     * Prefixes cells starting with =, +, -, @, tab, or CR with a single
     * quote so spreadsheet applications treat them as plain text.
     *
     * @param mixed $value The raw cell value.
     *
     * @return string The quoted and injection-safe cell string.
     */
    private function neutralizeCsvCell(mixed $value): string
    {
        $str = (string) $value;
        if (preg_match('/^[=+\-@\t\r]/', $str) === 1) {
            $str = "'".$str;
        }

        return '"'.str_replace('"', '""', $str).'"';
    }//end neutralizeCsvCell()
}//end class
