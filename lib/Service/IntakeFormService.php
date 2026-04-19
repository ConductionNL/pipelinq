<?php
// SPDX-License-Identifier: EUPL-1.2

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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
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
     * @param IAppConfig         $appConfig   The app configuration.
     * @param ContainerInterface $container   The DI container.
     * @param NotificationService $notification The notification service.
     * @param LoggerInterface    $logger      The logger.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private NotificationService $notification,
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
     */
    public function isRateLimited(string $ip, string $formId): bool
    {
        $key = 'pipelinq_intake_' . md5($ip . '_' . $formId);

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
            } elseif ($mapping === null) {
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
     */
    public function generateIframeEmbed(string $formId, string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/') . '/index.php/apps/pipelinq/api/public/forms/' . $formId;
        $src = htmlspecialchars($url);
        return '<iframe src="' . $src . '" width="100%" height="500" frameborder="0" style="border:none;"></iframe>';
    }//end generateIframeEmbed()

    /**
     * Generate JavaScript embed snippet for a form.
     *
     * @param string $formId  The form ID.
     * @param string $baseUrl The Nextcloud base URL.
     *
     * @return string The JavaScript embed snippet.
     */
    public function generateJsEmbed(string $formId, string $baseUrl): string
    {
        $url    = rtrim($baseUrl, '/') . '/index.php/apps/pipelinq/api/public/forms/' . $formId;
        $safeId = htmlspecialchars($formId);

        $js  = '<div id="pipelinq-form-' . $safeId . '"></div>' . "\n";
        $js .= "<script>\n";
        $js .= "(function(){\n";
        $js .= "  var c=document.getElementById('pipelinq-form-" . $safeId . "');\n";
        $js .= "  var f=document.createElement('iframe');\n";
        $js .= "  f.src='" . $url . "';\n";
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
     */
    public function exportCsv(array $submissions, array $fields): string
    {
        $headers = ['Submitted At', 'Status', 'Contact ID', 'Lead ID'];
        foreach ($fields as $field) {
            $headers[] = $field['label'] ?? $field['name'] ?? 'Unknown';
        }

        $rows = [implode(',', array_map(fn($h) => '"' . str_replace('"', '""', $h) . '"', $headers))];

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

            $rows[] = implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row));
        }//end foreach

        return implode("\n", $rows);
    }//end exportCsv()

    /**
     * Get a form's public-facing definition for rendering.
     *
     * @param string $formId The form ID.
     *
     * @return array|null The public form definition or null if not found.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function getPublicFormDefinition(string $formId): ?array
    {
        try {
            $form = $this->getFormData(formId: $formId);

            if ($form === null) {
                return null;
            }

            // Return only public-facing fields.
            return [
                'id'             => $form['uuid'] ?? $formId,
                'name'           => $form['name'] ?? '',
                'fields'         => $form['fields'] ?? [],
                'successMessage' => $form['successMessage'] ?? '',
                'isActive'       => $form['isActive'] ?? true,
            ];
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to get public form definition',
                context: ['formId' => $formId, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end getPublicFormDefinition()

    /**
     * Get a form's full data from OpenRegister.
     *
     * @param string $formId The form ID.
     *
     * @return array|null The form data or null if not found.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3
     */
    public function getFormData(string $formId): ?array
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $form = $objectService->find(
                id: $formId,
                register: $config['register'],
                schema: $config['intakeForm_schema']
            );

            if ($form === null) {
                return null;
            }

            return $this->serializeResult(result: $form);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to get form data',
                context: ['formId' => $formId, 'error' => $e->getMessage()]
            );
            return null;
        }
    }//end getFormData()

    /**
     * Process a form submission: validate, check spam/rate limit, create contact/lead, record submission.
     *
     * @param array  $formData   The form configuration from OpenRegister.
     * @param array  $submission The submitted form data.
     * @param string $ip         The submitter's IP address.
     *
     * @return array{success: bool, status: string, contactId: string|null, leadId: string|null, message: string}
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    public function processSubmission(array $formData, array $submission, string $ip): array
    {
        $formId = $formData['uuid'] ?? '';

        // Validate submission.
        $validation = $this->validateSubmission(form: $formData, submission: $submission);
        if ($validation['valid'] === false) {
            return [
                'success'   => false,
                'status'    => 'rejected',
                'contactId' => null,
                'leadId'    => null,
                'message'   => implode(', ', $validation['errors']),
            ];
        }

        // Deduplicate contact by email.
        $email   = $submission['email'] ?? null;
        $contact = null;
        $contactId = null;

        if ($email !== null) {
            $contact = $this->deduplicateContact(email: $email);
            if ($contact !== null) {
                $contactId = $contact['uuid'] ?? null;
            }
        }

        // If no existing contact, create a new one.
        if ($contactId === null) {
            $contactData = $this->mapToEntity(
                fieldMappings: $formData['fieldMappings'] ?? [],
                submission: $submission,
                entityType: 'contact'
            );
            if (empty($contactData) === false) {
                $contact = $this->createContact(data: $contactData);
                $contactId = $contact['uuid'] ?? null;
            }
        }

        // Create lead.
        $leadData = $this->mapToEntity(
            fieldMappings: $formData['fieldMappings'] ?? [],
            submission: $submission,
            entityType: 'lead'
        );
        if (empty($leadData) === false) {
            $leadData['client'] = $contactId;
            if (isset($formData['targetPipeline']) === true) {
                $leadData['pipeline'] = $formData['targetPipeline'];
            }
            if (isset($formData['targetStage']) === true) {
                $leadData['stage'] = $formData['targetStage'];
            }

            $lead = $this->createLead(data: $leadData);
            $leadId = $lead['uuid'] ?? null;
        } else {
            $leadId = null;
        }

        // Record submission.
        $submissionRecord = $this->recordSubmission(
            formId: $formId,
            contactId: $contactId,
            leadId: $leadId,
            data: $submission,
            ip: $ip,
            status: 'processed'
        );

        // Notify user.
        if (isset($formData['notifyUser']) === true && $formData['notifyUser'] !== '') {
            $this->notifyUser(
                userId: $formData['notifyUser'],
                formName: $formData['name'] ?? 'Form',
                contactEmail: $email,
                leadId: $leadId
            );
        }

        // Increment submit count.
        $formData['submitCount'] = ($formData['submitCount'] ?? 0) + 1;
        $this->updateFormSubmitCount(formId: $formId, count: $formData['submitCount']);

        return [
            'success'   => true,
            'status'    => 'processed',
            'contactId' => $contactId,
            'leadId'    => $leadId,
            'message'   => $formData['successMessage'] ?? 'Thank you for your submission.',
        ];
    }//end processSubmission()

    /**
     * Search for an existing contact by email address.
     *
     * @param string $email The email address to search.
     *
     * @return array|null The contact object if found, null otherwise.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    public function deduplicateContact(string $email): ?array
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $results = $objectService->findObjects(
                register: $config['register'],
                schema: $config['contact_schema'],
                params: ['email' => $email]
            );

            if (is_array($results) === true && isset($results[0]) === true) {
                return $results[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to deduplicate contact by email',
                context: ['error' => $e->getMessage()]
            );
            return null;
        }
    }//end deduplicateContact()

    /**
     * Create a new contact object.
     *
     * @param array $data The contact data.
     *
     * @return array The created contact object.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    private function createContact(array $data): array
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $result = $objectService->saveObject(
                register: $config['register'],
                schema: $config['contact_schema'],
                object: $data
            );

            return $this->serializeResult(result: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to create contact',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }
    }//end createContact()

    /**
     * Create a new lead object.
     *
     * @param array $data The lead data.
     *
     * @return array The created lead object.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    private function createLead(array $data): array
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $result = $objectService->saveObject(
                register: $config['register'],
                schema: $config['lead_schema'],
                object: $data
            );

            return $this->serializeResult(result: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to create lead',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }
    }//end createLead()

    /**
     * Record a form submission.
     *
     * @param string      $formId     The form ID.
     * @param string|null $contactId  The contact ID.
     * @param string|null $leadId     The lead ID.
     * @param array       $data       The submitted data.
     * @param string      $ip         The submitter's IP.
     * @param string      $status     The submission status.
     *
     * @return array The recorded submission object.
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    private function recordSubmission(
        string $formId,
        ?string $contactId,
        ?string $leadId,
        array $data,
        string $ip,
        string $status
    ): array {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $submission = [
                'form'        => $formId,
                'submittedAt' => (new \DateTime())->format('c'),
                'data'        => $data,
                'contactId'   => $contactId,
                'leadId'      => $leadId,
                'ip'          => $ip,
                'status'      => $status,
            ];

            $result = $objectService->saveObject(
                register: $config['register'],
                schema: $config['intakeSubmission_schema'],
                object: $submission
            );

            return $this->serializeResult(result: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to record submission',
                context: ['formId' => $formId, 'error' => $e->getMessage()]
            );
            return [];
        }
    }//end recordSubmission()

    /**
     * Notify a user about a new form submission.
     *
     * @param string      $userId     The user ID to notify.
     * @param string      $formName   The form name.
     * @param string|null $contactEmail The submitter email.
     * @param string|null $leadId     The created lead ID.
     *
     * @return void
     *
     * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2
     */
    private function notifyUser(
        string $userId,
        string $formName,
        ?string $contactEmail,
        ?string $leadId
    ): void {
        try {
            $this->notification->notifyAssignment(
                entityType: 'lead',
                title: 'New submission on ' . $formName,
                assigneeUserId: $userId,
                objectId: $leadId ?? '',
                author: 'system'
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to notify user of submission',
                context: ['userId' => $userId, 'error' => $e->getMessage()]
            );
        }
    }//end notifyUser()

    /**
     * Update the submission count for a form.
     *
     * @param string $formId The form ID.
     * @param int    $count  The new submission count.
     *
     * @return void
     */
    private function updateFormSubmitCount(string $formId, int $count): void
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->getConfig();

            $form = $objectService->find(
                id: $formId,
                register: $config['register'],
                schema: $config['intakeForm_schema']
            );

            if ($form === null) {
                return;
            }

            $formData = $form->getObject() ?? [];
            $formData['submitCount'] = $count;

            $objectService->saveObject(
                register: $config['register'],
                schema: $config['intakeForm_schema'],
                object: $formData
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: 'Failed to update form submit count',
                context: ['formId' => $formId, 'error' => $e->getMessage()]
            );
        }
    }//end updateFormSubmitCount()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available: ' . $e->getMessage());
        }
    }//end getObjectService()

    /**
     * Get the configured register and schema IDs.
     *
     * @return array{register: string, contact_schema: string, lead_schema: string,
     *                intakeForm_schema: string, intakeSubmission_schema: string}
     *
     * @throws \RuntimeException If configuration is missing.
     */
    private function getConfig(): array
    {
        $register            = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $contactSchema       = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
        $leadSchema          = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
        $intakeFormSchema    = $this->appConfig->getValueString(Application::APP_ID, 'intakeForm_schema', '');
        $intakeSubmissionSchema = $this->appConfig->getValueString(Application::APP_ID, 'intakeSubmission_schema', '');

        if (
            $register === '' || $contactSchema === '' || $leadSchema === '' ||
            $intakeFormSchema === '' || $intakeSubmissionSchema === ''
        ) {
            throw new \RuntimeException('Intake form register or schema not configured.');
        }

        return [
            'register'                  => $register,
            'contact_schema'            => $contactSchema,
            'lead_schema'               => $leadSchema,
            'intakeForm_schema'         => $intakeFormSchema,
            'intakeSubmission_schema'   => $intakeSubmissionSchema,
        ];
    }//end getConfig()

    /**
     * Serialize an object or array result to an array.
     *
     * @param mixed $result The result to serialize.
     *
     * @return array The serialized result.
     */
    private function serializeResult(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'getObject') === true) {
            return $result->getObject() ?? [];
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            return $result->jsonSerialize();
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serializeResult()
}//end class
