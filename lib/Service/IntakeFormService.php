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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for public intake form processing, spam protection, and entity creation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
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
     * Validate submission data against form field definitions.
     *
     * @param array $form       The form configuration.
     * @param array $submission The submitted data.
     *
     * @return array Validation result with 'valid' boolean and 'errors' array.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
     */
    public function validateSubmission(array $form, array $submission): array
    {
        $errors = [];
        $fields = $form['fields'] ?? [];

        foreach ($fields as $field) {
            $name     = $field['name'] ?? '';
            $required = $field['required'] ?? false;
            $type     = $field['type'] ?? 'text';
            $value    = $submission[$name] ?? null;

            if ($required === true && (empty($value) === true && $value !== '0')) {
                $errors[] = sprintf('Field "%s" is required', $name);
                continue;
            }

            if (empty($value) === true) {
                continue;
            }

            if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = sprintf('Field "%s" must be a valid email address', $name);
            }
        }//end foreach

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }//end validateSubmission()

    /**
     * Check if a submission is spam (honeypot field filled).
     *
     * @param array $submission The submitted data.
     *
     * @return bool True if the submission is detected as spam.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
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
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
     */
    public function isRateLimited(string $ip, string $formId): bool
    {
        $key = 'pipelinq_intake_'.md5($ip.'_'.$formId);

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
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
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
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
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
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
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
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
     */
    public function exportCsv(array $submissions, array $fields): string
    {
        $headers = ['Submitted At', 'Status', 'Contact ID', 'Lead ID'];
        foreach ($fields as $field) {
            $headers[] = $field['label'] ?? $field['name'] ?? 'Unknown';
        }

        $rows = [implode(',', array_map(fn($h) => '"'.str_replace('"', '""', $h).'"', $headers))];

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

            $rows[] = implode(',', array_map(fn($v) => '"'.str_replace('"', '""', (string) $v).'"', $row));
        }//end foreach

        return implode("\n", $rows);
    }//end exportCsv()
}//end class
