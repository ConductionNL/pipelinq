<?php

/**
 * Unit tests for ContactDataBuilder.
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

use OCA\Pipelinq\Service\ContactDataBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactDataBuilder.
 */
class ContactDataBuilderTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ContactDataBuilder
     */
    private ContactDataBuilder $builder;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder = new ContactDataBuilder();
    }//end setUp()

    /**
     * Test buildClientImportData for person contact.
     *
     * @return void
     */
    public function testBuildClientImportDataPerson(): void
    {
        $ncContact = [
            'FN'    => 'John Doe',
            'ORG'   => 'Acme Corp',
            'EMAIL' => 'john@example.com',
            'TEL'   => '+31612345678',
        ];

        $result = $this->builder->buildClientImportData($ncContact, 'uid-123');

        $this->assertSame('John Doe', $result['name']);
        $this->assertSame('person', $result['type']);
        $this->assertSame('john@example.com', $result['email']);
        $this->assertSame('Acme Corp', $result['industry']);
        $this->assertSame('uid-123', $result['contactsUid']);
    }//end testBuildClientImportDataPerson()

    /**
     * Test buildClientImportData for organization.
     *
     * @return void
     */
    public function testBuildClientImportDataOrganization(): void
    {
        $ncContact = [
            'FN'  => 'Acme Corp',
            'ORG' => 'Acme Corp',
        ];

        $result = $this->builder->buildClientImportData($ncContact, 'uid-456');

        $this->assertSame('Acme Corp', $result['name']);
        $this->assertSame('organization', $result['type']);
    }//end testBuildClientImportDataOrganization()

    /**
     * Test buildClientImportData with array values.
     *
     * @return void
     */
    public function testBuildClientImportDataArrayValues(): void
    {
        $ncContact = [
            'FN'    => ['Jane Smith'],
            'EMAIL' => ['jane@example.com', 'jane2@example.com'],
        ];

        $result = $this->builder->buildClientImportData($ncContact, 'uid-789');

        $this->assertSame('Jane Smith', $result['name']);
        $this->assertSame('jane@example.com', $result['email']);
    }//end testBuildClientImportDataArrayValues()

    /**
     * Test buildContactImportData builds contact data.
     *
     * @return void
     */
    public function testBuildContactImportData(): void
    {
        $ncContact = [
            'FN'    => 'John Doe',
            'EMAIL' => 'john@example.com',
            'ROLE'  => 'Developer',
        ];

        $result = $this->builder->buildContactImportData($ncContact, 'uid-123', 'client-1');

        $this->assertSame('John Doe', $result['name']);
        $this->assertSame('john@example.com', $result['email']);
        $this->assertSame('Developer', $result['role']);
        $this->assertSame('client-1', $result['client']);
    }//end testBuildContactImportData()

    /**
     * Test buildContactImportData without client ID.
     *
     * @return void
     */
    public function testBuildContactImportDataNoClient(): void
    {
        $ncContact = ['FN' => 'Jane'];

        $result = $this->builder->buildContactImportData($ncContact, 'uid-456', null);

        $this->assertArrayNotHasKey('client', $result);
    }//end testBuildContactImportDataNoClient()

    /**
     * Test buildClientImportData with empty org uses name as org.
     *
     * @return void
     */
    public function testBuildClientImportDataEmptyNameUsesOrg(): void
    {
        $ncContact = [
            'FN'  => '',
            'ORG' => 'SomeCorp',
        ];

        $result = $this->builder->buildClientImportData($ncContact, 'uid-x');

        $this->assertSame('SomeCorp', $result['name']);
        $this->assertSame('organization', $result['type']);
    }//end testBuildClientImportDataEmptyNameUsesOrg()
}//end class
