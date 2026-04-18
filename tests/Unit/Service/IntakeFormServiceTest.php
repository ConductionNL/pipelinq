<?php

/**
 * Unit tests for IntakeFormService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntakeFormService;
use OCP\IAppConfig;
use OCP\IAppManager;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for IntakeFormService.
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
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Mock app manager.
     *
     * @var IAppManager
     */
    private IAppManager $appManager;

    /**
     * Mock server container.
     *
     * @var IServerContainer
     */
    private IServerContainer $container;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(IServerContainer::class);

        $this->service = new IntakeFormService(
            $this->appConfig,
            $this->logger,
            $this->appManager,
            $this->container,
        );
    }//end setUp()

    /**
     * Test validateSubmission with valid data passes validation.
     *
     * @return void
     */
    public function testValidateSubmissionWithValidDataPasses(): void
    {
        $form = [
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];

        $result = $this->service->validateSubmission($form, $submission);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }//end testValidateSubmissionWithValidDataPasses()

    /**
     * Test validateSubmission with missing required field fails validation.
     *
     * @return void
     */
    public function testValidateSubmissionWithMissingRequiredFieldFails(): void
    {
        $form = [
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'name' => 'John Doe',
            // email is missing
        ];

        $result = $this->service->validateSubmission($form, $submission);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('email', $result['errors'][0]);
    }//end testValidateSubmissionWithMissingRequiredFieldFails()

    /**
     * Test validateSubmission with invalid email format fails validation.
     *
     * @return void
     */
    public function testValidateSubmissionWithInvalidEmailFormatFails(): void
    {
        $form = [
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'required' => true],
            ],
        ];

        $submission = [
            'email' => 'not-an-email',
        ];

        $result = $this->service->validateSubmission($form, $submission);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('email', $result['errors'][0]);
    }//end testValidateSubmissionWithInvalidEmailFormatFails()

    /**
     * Test isSpam detects honeypot field.
     *
     * @return void
     */
    public function testIsSpamDetectsHoneypot(): void
    {
        $submission = [
            'name' => 'Test',
            '_hp_field' => 'something',
        ];

        $isSpam = $this->service->isSpam($submission);

        $this->assertTrue($isSpam);
    }//end testIsSpamDetectsHoneypot()

    /**
     * Test isSpam returns false for clean submission.
     *
     * @return void
     */
    public function testIsSpamReturnsFalseForCleanSubmission(): void
    {
        $submission = [
            'name' => 'Test',
            'email' => 'test@example.com',
        ];

        $isSpam = $this->service->isSpam($submission);

        $this->assertFalse($isSpam);
    }//end testIsSpamReturnsFalseForCleanSubmission()

    /**
     * Test mapToEntity with contact target.
     *
     * @return void
     */
    public function testMapToEntityWithContactTarget(): void
    {
        $mappings = [
            'name' => ['entity' => 'contact', 'property' => 'name'],
            'email' => ['entity' => 'contact', 'property' => 'email'],
            'subject' => ['entity' => 'lead', 'property' => 'title'],
        ];

        $submission = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Test Subject',
        ];

        $result = $this->service->mapToEntity($mappings, $submission, 'contact');

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('subject', $result);
        $this->assertSame('John Doe', $result['name']);
        $this->assertSame('john@example.com', $result['email']);
    }//end testMapToEntityWithContactTarget()

    /**
     * Test mapToEntity with lead target.
     *
     * @return void
     */
    public function testMapToEntityWithLeadTarget(): void
    {
        $mappings = [
            'subject' => ['entity' => 'lead', 'property' => 'title'],
            'description' => ['entity' => 'lead', 'property' => 'notes'],
            'name' => ['entity' => 'contact', 'property' => 'name'],
        ];

        $submission = [
            'subject' => 'Test Subject',
            'description' => 'Test Description',
            'name' => 'John Doe',
        ];

        $result = $this->service->mapToEntity($mappings, $submission, 'lead');

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('notes', $result);
        $this->assertSame('Test Subject', $result['title']);
        $this->assertSame('Test Description', $result['notes']);
    }//end testMapToEntityWithLeadTarget()

    /**
     * Test generateIframeEmbed returns valid HTML.
     *
     * @return void
     */
    public function testGenerateIframeEmbedReturnsValidHtml(): void
    {
        $result = $this->service->generateIframeEmbed('form-id-123', 'https://example.com/');

        $this->assertStringContainsString('<iframe', $result);
        $this->assertStringContainsString('form-id-123', $result);
        $this->assertStringContainsString('frameborder="0"', $result);
        $this->assertStringContainsString('https://example.com', $result);
    }//end testGenerateIframeEmbedReturnsValidHtml()

    /**
     * Test generateJsEmbed returns valid JavaScript.
     *
     * @return void
     */
    public function testGenerateJsEmbedReturnsValidJavaScript(): void
    {
        $result = $this->service->generateJsEmbed('form-id-456', 'https://example.com/');

        $this->assertStringContainsString('<div id="pipelinq-form-', $result);
        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('</script>', $result);
        $this->assertStringContainsString('form-id-456', $result);
        $this->assertStringContainsString('https://example.com', $result);
    }//end testGenerateJsEmbedReturnsValidJavaScript()

    /**
     * Test exportCsv generates valid CSV content.
     *
     * @return void
     */
    public function testExportCsvGeneratesValidCsvContent(): void
    {
        $submissions = [
            [
                'submittedAt' => '2024-01-01T12:00:00Z',
                'status' => 'processed',
                'contactId' => 'contact-1',
                'leadId' => 'lead-1',
                'data' => ['name' => 'John Doe', 'email' => 'john@example.com'],
            ],
        ];

        $fields = [
            ['name' => 'name', 'label' => 'Name'],
            ['name' => 'email', 'label' => 'Email'],
        ];

        $result = $this->service->exportCsv($submissions, $fields);

        $this->assertStringContainsString('Submitted At', $result);
        $this->assertStringContainsString('Status', $result);
        $this->assertStringContainsString('Contact ID', $result);
        $this->assertStringContainsString('Lead ID', $result);
        $this->assertStringContainsString('Name', $result);
        $this->assertStringContainsString('Email', $result);
        $this->assertStringContainsString('processed', $result);
        $this->assertStringContainsString('John Doe', $result);
    }//end testExportCsvGeneratesValidCsvContent()
}//end class
