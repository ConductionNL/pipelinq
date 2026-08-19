<?php

/**
 * Unit tests for ContactVcardPropertyBuilder.
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

use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for ContactVcardPropertyBuilder.
 */
class ContactVcardPropertyBuilderTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ContactVcardPropertyBuilder
     */
    private ContactVcardPropertyBuilder $builder;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $container = $this->createMock(ContainerInterface::class);

        $this->builder = new ContactVcardPropertyBuilder($appConfig, $container);
    }//end setUp()

    /**
     * Test buildProperties returns FN for client.
     *
     * @return void
     */
    public function testBuildPropertiesReturnsFnForClient(): void
    {
        $result = $this->builder->buildProperties(
            ['name' => 'Test Corp', 'email' => 'test@test.com', 'type' => 'organization'],
            'client'
        );

        $this->assertSame('Test Corp', $result['FN']);
        $this->assertSame('test@test.com', $result['EMAIL']);
        $this->assertSame('Test Corp', $result['ORG']);
    }//end testBuildPropertiesReturnsFnForClient()

    /**
     * Test buildProperties includes phone.
     *
     * @return void
     */
    public function testBuildPropertiesIncludesPhone(): void
    {
        $result = $this->builder->buildProperties(
            ['name' => 'John', 'phone' => '+31612345678'],
            'contact'
        );

        $this->assertSame('+31612345678', $result['TEL']);
    }//end testBuildPropertiesIncludesPhone()

    /**
     * Test buildProperties includes client website and notes.
     *
     * @return void
     */
    public function testBuildPropertiesIncludesClientWebsiteAndNotes(): void
    {
        $result = $this->builder->buildProperties(
            ['name' => 'Corp', 'website' => 'https://example.com', 'notes' => 'Important', 'address' => 'Street 1'],
            'client'
        );

        $this->assertSame('https://example.com', $result['URL']);
        $this->assertSame('Important', $result['NOTE']);
        $this->assertSame('Street 1', $result['ADR']);
    }//end testBuildPropertiesIncludesClientWebsiteAndNotes()

    /**
     * Test buildProperties includes contact role.
     *
     * @return void
     */
    public function testBuildPropertiesIncludesContactRole(): void
    {
        $result = $this->builder->buildProperties(
            ['name' => 'Jane', 'role' => 'Manager'],
            'contact'
        );

        $this->assertSame('Manager', $result['ROLE']);
    }//end testBuildPropertiesIncludesContactRole()

    /**
     * Test buildProperties defaults to Unknown for missing name.
     *
     * @return void
     */
    public function testBuildPropertiesDefaultsToUnknown(): void
    {
        $result = $this->builder->buildProperties([], 'client');

        $this->assertSame('Unknown', $result['FN']);
    }//end testBuildPropertiesDefaultsToUnknown()
}//end class
