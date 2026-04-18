<?php

/**
 * Unit tests for IntakeFormService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntakeFormService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for IntakeFormService.
 *
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-2.1
 */
class IntakeFormServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var IntakeFormService
     */
    private IntakeFormService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->service   = new IntakeFormService(
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test validateSubmission with valid data.
     *
     * @return void
     */
    public function testValidateSubmissionValid(): void
    {
        $form = [
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ];

        $result = $this->service->validateSubmission(form: $form, submission: $submission);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }//end testValidateSubmissionValid()

    /**
     * Test validateSubmission with missing required field.
     *
     * @return void
     */
    public function testValidateSubmissionMissingRequired(): void
    {
        $form = [
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'name' => 'John Doe',
        ];

        $result = $this->service->validateSubmission(form: $form, submission: $submission);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('email', $result['errors'][0]);
    }//end testValidateSubmissionMissingRequired()

    /**
     * Test validateSubmission with invalid email.
     *
     * @return void
     */
    public function testValidateSubmissionInvalidEmail(): void
    {
        $form = [
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'email' => 'not-an-email',
        ];

        $result = $this->service->validateSubmission(form: $form, submission: $submission);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('valid email', $result['errors'][0]);
    }//end testValidateSubmissionInvalidEmail()

    /**
     * Test isSpam with honeypot field filled.
     *
     * @return void
     */
    public function testIsSpamWithHoneypot(): void
    {
        $submission = [
            'name'      => 'Test',
            '_hp_field' => 'filled',
        ];

        $this->assertTrue($this->service->isSpam(submission: $submission));
    }//end testIsSpamWithHoneypot()

    /**
     * Test isSpam without honeypot field.
     *
     * @return void
     */
    public function testIsSpamWithoutHoneypot(): void
    {
        $submission = [
            'name' => 'Test',
        ];

        $this->assertFalse($this->service->isSpam(submission: $submission));
    }//end testIsSpamWithoutHoneypot()

    /**
     * Test isSpam with empty honeypot field.
     *
     * @return void
     */
    public function testIsSpamWithEmptyHoneypot(): void
    {
        $submission = [
            'name'      => 'Test',
            '_hp_field' => '',
        ];

        $this->assertFalse($this->service->isSpam(submission: $submission));
    }//end testIsSpamWithEmptyHoneypot()

    /**
     * Test mapToEntity for contact.
     *
     * @return void
     */
    public function testMapToEntityContact(): void
    {
        $fieldMappings = [
            'full_name' => ['entity' => 'contact', 'property' => 'name'],
            'phone'     => ['entity' => 'contact', 'property' => 'phone'],
        ];

        $submission = [
            'full_name' => 'Jane Doe',
            'phone'     => '555-1234',
            'message'   => 'Hello',
        ];

        $result = $this->service->mapToEntity(
            fieldMappings: $fieldMappings,
            submission: $submission,
            entityType: 'contact'
        );

        $this->assertEquals('Jane Doe', $result['name']);
        $this->assertEquals('555-1234', $result['phone']);
    }//end testMapToEntityContact()

    /**
     * Test mapToEntity for lead with unmapped fields.
     *
     * @return void
     */
    public function testMapToEntityLeadWithUnmapped(): void
    {
        $fieldMappings = [
            'title' => ['entity' => 'lead', 'property' => 'title'],
        ];

        $submission = [
            'title'   => 'Test Lead',
            'message' => 'Extra info',
            'source'  => 'website',
        ];

        $result = $this->service->mapToEntity(
            fieldMappings: $fieldMappings,
            submission: $submission,
            entityType: 'lead'
        );

        $this->assertEquals('Test Lead', $result['title']);
        $this->assertNotEmpty($result['notes']);
    }//end testMapToEntityLeadWithUnmapped()

    /**
     * Test generateIframeEmbed.
     *
     * @return void
     */
    public function testGenerateIframeEmbed(): void
    {
        $formId  = 'test-form-123';
        $baseUrl = 'https://example.com/';
        $result  = $this->service->generateIframeEmbed(formId: $formId, baseUrl: $baseUrl);

        $this->assertStringContainsString('<iframe', $result);
        $this->assertStringContainsString('src=', $result);
        $this->assertStringContainsString($formId, $result);
        $this->assertStringContainsString('public/forms', $result);
    }//end testGenerateIframeEmbed()

    /**
     * Test generateJsEmbed.
     *
     * @return void
     */
    public function testGenerateJsEmbed(): void
    {
        $formId  = 'test-form-456';
        $baseUrl = 'https://example.com/';
        $result  = $this->service->generateJsEmbed(formId: $formId, baseUrl: $baseUrl);

        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('pipelinq-form-', $result);
        $this->assertStringContainsString('public/forms', $result);
        $this->assertStringContainsString($formId, $result);
    }//end testGenerateJsEmbed()

    /**
     * Test exportCsv with submissions.
     *
     * @return void
     */
    public function testExportCsv(): void
    {
        $fields = [
            ['name' => 'name', 'label' => 'Full Name'],
            ['name' => 'email', 'label' => 'Email Address'],
        ];

        $submissions = [
            [
                'submittedAt' => '2024-01-01T12:00:00Z',
                'status'      => 'processed',
                'contactId'   => 'contact-123',
                'leadId'      => 'lead-456',
                'data'        => [
                    'name'  => 'John Doe',
                    'email' => 'john@example.com',
                ],
            ],
        ];

        $csv = $this->service->exportCsv(submissions: $submissions, fields: $fields);

        $this->assertStringContainsString('Submitted At', $csv);
        $this->assertStringContainsString('Status', $csv);
        $this->assertStringContainsString('Full Name', $csv);
        $this->assertStringContainsString('Email Address', $csv);
        $this->assertStringContainsString('John Doe', $csv);
        $this->assertStringContainsString('john@example.com', $csv);
    }//end testExportCsv()

    /**
     * Test exportCsv with empty submissions.
     *
     * @return void
     */
    public function testExportCsvEmpty(): void
    {
        $fields = [
            ['name' => 'name', 'label' => 'Full Name'],
        ];

        $csv = $this->service->exportCsv(submissions: [], fields: $fields);

        $this->assertStringContainsString('Submitted At', $csv);
        $this->assertStringContainsString('Full Name', $csv);
    }//end testExportCsvEmpty()
}//end class
