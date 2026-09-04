<?php

/**
 * Unit tests for ContactDataBuilder.
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

use OCA\Pipelinq\Service\ContactDataBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactDataBuilder.
 */
class ContactDataBuilderTest extends TestCase {
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
	protected function setUp(): void {
		$this->builder = new ContactDataBuilder();
	}//end setUp()

	/**
	 * Test buildClientImportData for person contact.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataPerson(): void {
		$ncContact = [
			'FN' => 'John Doe',
			'ORG' => 'Acme Corp',
			'EMAIL' => 'john@example.com',
			'TEL' => '+31612345678',
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
	public function testBuildClientImportDataOrganization(): void {
		$ncContact = [
			'FN' => 'Acme Corp',
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
	public function testBuildClientImportDataArrayValues(): void {
		$ncContact = [
			'FN' => ['Jane Smith'],
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
	public function testBuildContactImportData(): void {
		$ncContact = [
			'FN' => 'John Doe',
			'EMAIL' => 'john@example.com',
			'ROLE' => 'Developer',
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
	public function testBuildContactImportDataNoClient(): void {
		$ncContact = ['FN' => 'Jane'];

		$result = $this->builder->buildContactImportData($ncContact, 'uid-456', null);

		$this->assertArrayNotHasKey('client', $result);
	}//end testBuildContactImportDataNoClient()

	/**
	 * Test buildClientImportData with empty org uses name as org.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataEmptyNameUsesOrg(): void {
		$ncContact = [
			'FN' => '',
			'ORG' => 'SomeCorp',
		];

		$result = $this->builder->buildClientImportData($ncContact, 'uid-x');

		$this->assertSame('SomeCorp', $result['name']);
		$this->assertSame('organization', $result['type']);
	}//end testBuildClientImportDataEmptyNameUsesOrg()

	/**
	 * Multi-valued typed EMAIL/TEL (IManager `types` => true shape) build
	 * `emails[]`/`phones[]` with kind mapped from TYPE and the first entry
	 * marked primary, while `email`/`phone` mirror that first entry.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataTypedEmailsAndPhones(): void {
		$ncContact = [
			'FN' => 'John Doe',
			'EMAIL' => [
				['type' => 'WORK', 'value' => 'john@work.example'],
				['type' => 'HOME', 'value' => 'john@home.example'],
			],
			'TEL' => [
				['type' => 'CELL', 'value' => '+31611111111'],
				['type' => 'WORK', 'value' => '+31622222222'],
			],
		];

		$result = $this->builder->buildClientImportData($ncContact, 'uid-typed');

		$this->assertSame('john@work.example', $result['email']);
		$this->assertSame('+31611111111', $result['phone']);

		$this->assertSame(
			[
				['kind' => 'work', 'value' => 'john@work.example', 'primary' => true, 'verified' => false],
				['kind' => 'private', 'value' => 'john@home.example', 'primary' => false, 'verified' => false],
			],
			$result['emails']
		);
		$this->assertSame(
			[
				['kind' => 'mobile', 'value' => '+31611111111', 'primary' => true, 'verified' => false],
				['kind' => 'work', 'value' => '+31622222222', 'primary' => false, 'verified' => false],
			],
			$result['phones']
		);
	}//end testBuildClientImportDataTypedEmailsAndPhones()

	/**
	 * An untyped TEL (no TYPE parameter) maps to kind "other" rather than
	 * guessing.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataUntypedPhoneMapsToOther(): void {
		$ncContact = ['FN' => 'Jane', 'TEL' => '+31699999999'];

		$result = $this->builder->buildClientImportData($ncContact, 'uid-untyped');

		$this->assertSame(
			[['kind' => 'other', 'value' => '+31699999999', 'primary' => true, 'verified' => false]],
			$result['phones']
		);
	}//end testBuildClientImportDataUntypedPhoneMapsToOther()

	/**
	 * X-SOCIALPROFILE entries build `socialProfiles[]`: a recognised
	 * network TYPE is kept, an unrecognised one falls back to "other", and
	 * a value that looks like a URL is stored as `url` rather than
	 * `handle`.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataSocialProfiles(): void {
		$ncContact = [
			'FN' => 'Jane',
			'X-SOCIALPROFILE' => [
				['type' => 'linkedin', 'value' => 'https://linkedin.com/in/jane'],
				['type' => 'mastodon', 'value' => '@jane@mastodon.social'],
				['type' => 'unknown-network', 'value' => 'janedoe'],
			],
		];

		$result = $this->builder->buildClientImportData($ncContact, 'uid-social');

		$this->assertSame(
			[
				['network' => 'linkedin', 'handle' => '', 'url' => 'https://linkedin.com/in/jane', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
				['network' => 'mastodon', 'handle' => '@jane@mastodon.social', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
				['network' => 'other', 'handle' => 'janedoe', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
			],
			$result['socialProfiles']
		);
	}//end testBuildClientImportDataSocialProfiles()

	/**
	 * No EMAIL/TEL/X-SOCIALPROFILE at all yields empty (not missing)
	 * arrays, so a form reading `emails`/`phones`/`socialProfiles` never
	 * has to guard against an absent key.
	 *
	 * @return void
	 */
	public function testBuildClientImportDataNoChannelsYieldsEmptyArrays(): void {
		$result = $this->builder->buildClientImportData(['FN' => 'No Channels'], 'uid-none');

		$this->assertSame([], $result['emails']);
		$this->assertSame([], $result['phones']);
		$this->assertSame([], $result['socialProfiles']);
		$this->assertArrayNotHasKey('email', $result);
		$this->assertArrayNotHasKey('phone', $result);
	}//end testBuildClientImportDataNoChannelsYieldsEmptyArrays()

	/**
	 * buildContactImportData carries the same typed-array mapping as the
	 * client builder.
	 *
	 * @return void
	 */
	public function testBuildContactImportDataTypedEmails(): void {
		$ncContact = [
			'FN' => 'John Doe',
			'EMAIL' => [['type' => 'WORK', 'value' => 'john@work.example']],
		];

		$result = $this->builder->buildContactImportData($ncContact, 'uid-123', null);

		$this->assertSame('john@work.example', $result['email']);
		$this->assertSame(
			[['kind' => 'work', 'value' => 'john@work.example', 'primary' => true, 'verified' => false]],
			$result['emails']
		);
	}//end testBuildContactImportDataTypedEmails()
}//end class
