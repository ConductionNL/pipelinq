<?php

/**
 * Unit tests for TemplateService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\TemplateService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the template resolution and validation service.
 */
class TemplateServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var TemplateService
     */
    private TemplateService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn(
            [
                [
                    '@self'      => ['id' => 'tpl-1'],
                    'externalId' => 'afspraak_nl',
                    'language'   => 'nl',
                    'status'     => 'approved',
                    'body'       => 'Beste {{1}}, uw afspraak op {{2}} om {{3}} is bevestigd.',
                ],
                [
                    '@self'      => ['id' => 'tpl-2'],
                    'externalId' => 'opt_in_nl',
                    'status'     => 'pending',
                    'body'       => 'Mag ik je op de hoogte houden?',
                ],
            ]
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg'],
                ['pipelinq', 'messageTemplate_schema', '', 'tpl'],
            ]
        );

        $this->service = new TemplateService($container, $appConfig, $this->createMock(LoggerInterface::class));
    }//end setUp()

    /**
     * Distinct {{N}} placeholders are counted.
     *
     * @return void
     */
    public function testPlaceholderCount(): void
    {
        $this->assertSame(3, $this->service->placeholderCount('Beste {{1}}, op {{2}} om {{3}}'));
        $this->assertSame(0, $this->service->placeholderCount('Geen placeholders'));
        // Repeated placeholder counts once.
        $this->assertSame(1, $this->service->placeholderCount('{{1}} en nog eens {{1}}'));
    }//end testPlaceholderCount()

    /**
     * Parameter validation matches the placeholder count.
     *
     * @return void
     */
    public function testValidateParameters(): void
    {
        $template = $this->service->find('tpl-1');
        $this->assertNotNull($template);

        $ok = $this->service->validateParameters($template, ['Jan', 'vrijdag', '14:00']);
        $this->assertTrue($ok['valid']);
        $this->assertSame(3, $ok['expected']);

        $bad = $this->service->validateParameters($template, ['Jan', 'vrijdag']);
        $this->assertFalse($bad['valid']);
        $this->assertSame(3, $bad['expected']);
        $this->assertSame(2, $bad['given']);
    }//end testValidateParameters()

    /**
     * Approval status gating.
     *
     * @return void
     */
    public function testIsApproved(): void
    {
        $this->assertTrue($this->service->isApproved($this->service->find('tpl-1')));
        $this->assertFalse($this->service->isApproved($this->service->find('tpl-2')));
    }//end testIsApproved()
}//end class
