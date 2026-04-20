<?php

/**
 * Unit tests for ContactLinkedUidsService.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactLinkedUidsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ContactLinkedUidsService.
 */
class ContactLinkedUidsServiceTest extends TestCase
{
    /**
     * Test getLinkedContactsUids handles missing schema gracefully.
     *
     * @return void
     */
    public function testGetLinkedUidsHandlesMissingSchema(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $objectService = new class {
            public function findAll(array $params, bool $_rbac, bool $_multitenancy): array
            {
                return [];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new ContactLinkedUidsService($appConfig, $container, $logger);

        // With empty register/schema, should return empty.
        $this->assertSame([], $service->getLinkedContactsUids());
    }//end testGetLinkedUidsHandlesMissingSchema()
}//end class
