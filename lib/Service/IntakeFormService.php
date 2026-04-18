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
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for public intake form processing, spam protection, and entity creation.
 *
 * @spec                                           openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2-1
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
     * @param IUserManager       $userManager The user manager.
     * @param IManager           $notifier    The notification manager.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private IUserManager $userManager,
        private IManager $notifier,
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

    /**
     * Process a form submission: validate, create/match contact, create lead, record submission.
     *
     * @param array  $form       The form configuration.
     * @param array  $submission The submitted data.
     * @param string $ip         The submitter's IP address.
     *
     * @return array Result with 'success' boolean, 'message', and optional 'contactId', 'leadId'.
     * @spec   openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2-1
     */
    public function processSubmission(array $form, array $submission, string $ip): array
    {
        try {
            // Validate submission.
            $validation = $this->validateSubmission(
                form: $form,
                submission: $submission
            );
            if ($validation['valid'] === false) {
                return [
                    'success' => false,
                    'message' => 'Validation failed: '.implode(', ', $validation['errors']),
                ];
            }

            // Get ObjectService for entity creation.
            $objectService = $this->getObjectService();
            $config        = $this->appConfig->getValueString('pipelinq', 'register', '');

            // Map fields to contact and lead.
            $fieldMappings = $form['fieldMappings'] ?? [];
            $contactData   = $this->mapToEntity(
                fieldMappings: $fieldMappings,
                submission: $submission,
                entityType: 'contact'
            );
            $leadData      = $this->mapToEntity(
                fieldMappings: $fieldMappings,
                submission: $submission,
                entityType: 'lead'
            );

            // Extract email for contact deduplication.
            $email = $submission[array_search('email', array_column($form['fields'] ?? [], 'name'))] ?? null;
            if ($email === false) {
                $email = null;
            }

            // Deduplicate or create contact.
            $contactId = null;
            if (empty($email) === false) {
                $existingContact = $this->deduplicateContact(
                    email: $email,
                    objectService: $objectService,
                    config: $config
                );
                if ($existingContact !== null) {
                    $contactId = $existingContact['id'] ?? $existingContact['uuid'] ?? null;
                }
            }

            if (empty($contactId) === true && empty($contactData) === false) {
                $newContact = $objectService->saveObject(
                    $contactData,
                    [],
                    $config,
                    'contact',
                    null,
                    _rbac: false,
                    _multitenancy: false
                );
                $contactId  = $newContact['id'] ?? $newContact['uuid'] ?? null;
            }

            // Create lead.
            $leadId = null;
            if (empty($leadData) === false) {
                if (empty($contactId) === false) {
                    $leadData['contact'] = $contactId;
                }

                // Add form configuration to lead.
                if (empty($form['targetPipeline']) === false) {
                    $leadData['pipeline'] = $form['targetPipeline'];
                }

                if (empty($form['targetStage']) === false) {
                    $leadData['stage'] = $form['targetStage'];
                }

                if (empty($form['title']) === false) {
                    $leadData['title'] = $form['title'];
                }

                $newLead = $objectService->saveObject(
                    $leadData,
                    [],
                    $config,
                    'lead',
                    null,
                    _rbac: false,
                    _multitenancy: false
                );
                $leadId  = $newLead['id'] ?? $newLead['uuid'] ?? null;
            }//end if

            // Record submission.
            $submissionRecord = [
                'form'        => $form['id'] ?? $form['uuid'] ?? '',
                'submittedAt' => date('c'),
                'data'        => $submission,
                'contactId'   => $contactId,
                'leadId'      => $leadId,
                'ip'          => $ip,
                'status'      => 'processed',
            ];

            $savedSubmission = $objectService->saveObject(
                $submissionRecord,
                [],
                $config,
                'intakeSubmission',
                null,
                _rbac: false,
                _multitenancy: false
            );

            // Increment submit count on form.
            if (empty($form['id'] ?? $form['uuid'] ?? null) === false) {
                $formId       = $form['id'] ?? $form['uuid'];
                $currentCount = $form['submitCount'] ?? 0;
                $form['submitCount'] = $currentCount + 1;

                $objectService->saveObject(
                    $form,
                    [],
                    $config,
                    'intakeForm',
                    $formId,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            // Notify configured user.
            if (empty($form['notifyUser']) === false) {
                $this->notifySubmission(
                    userId: $form['notifyUser'],
                    form: $form,
                    contactId: $contactId,
                    leadId: $leadId
                );
            }

            return [
                'success'   => true,
                'message'   => $form['successMessage'] ?? 'Thank you for your submission.',
                'contactId' => $contactId,
                'leadId'    => $leadId,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Intake form submission error: '.$e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while processing your submission. Please try again.',
            ];
        }//end try
    }//end processSubmission()

    /**
     * Deduplicate a contact by email.
     *
     * @param string $email         The email address.
     * @param mixed  $objectService The ObjectService.
     * @param string $config        The register name.
     *
     * @return array|null The contact object or null if not found.
     */
    private function deduplicateContact(string $email, mixed $objectService, string $config): ?array
    {
        try {
            $result = $objectService->findAll(
                register: $config,
                schema: 'contact',
                filters: ['email' => $email, '_limit' => 1],
            );

            $contacts = $result['results'] ?? [];
            if (empty($contacts) === false) {
                return $contacts[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Contact deduplication error: '.$e->getMessage());
            return null;
        }
    }//end deduplicateContact()

    /**
     * Notify a user of a new form submission.
     *
     * @param string      $userId    The Nextcloud user ID.
     * @param array       $form      The form.
     * @param string|null $contactId The created contact ID.
     * @param string|null $leadId    The created lead ID.
     *
     * @return void
     */
    private function notifySubmission(string $userId, array $form, ?string $contactId, ?string $leadId): void
    {
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return;
            }

            // Create notification.
            $notification = $this->notifier->createNotification();
            $notification
                ->setApp('pipelinq')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('form', $form['id'] ?? $form['uuid'] ?? '')
                ->setSubject(
                    'form_submission',
                    [
                        'form'       => $form['name'] ?? 'Unknown',
                        'contact_id' => $contactId,
                        'lead_id'    => $leadId,
                    ]
                )
                ->setMessage(
                    'form_submission_message',
                    [
                        'form' => $form['name'] ?? 'Unknown',
                    ]
                );

            $this->notifier->notify($notification);
        } catch (\Exception $e) {
            $msg = 'Failed to notify user '.$userId.' of form submission: '.$e->getMessage();
            $this->logger->warning($msg);
        }//end try
    }//end notifySubmission()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return mixed The ObjectService.
     */
    private function getObjectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
