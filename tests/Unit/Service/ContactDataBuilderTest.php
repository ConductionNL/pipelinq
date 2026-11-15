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

		$this->assertSame(expected: 'John Doe', actual: $result['name']);
		$this->assertSame(expected: 'person', actual: $result['type']);
		$this->assertSame(expected: 'john@example.com', actual: $result['email']);
		$this->assertSame(expected: ['Acme Corp'], actual: $result['industry']);
		$this->assertSame(expected: 'uid-123', actual: $result['contactsUid']);
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

		$this->assertSame(expected: 'Acme Corp', actual: $result['name']);
		$this->assertSame(expected: 'organization', actual: $result['type']);
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

		$this->assertSame(expected: 'Jane Smith', actual: $result['name']);
		$this->assertSame(expected: 'jane@example.com', actual: $result['email']);
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

		$this->assertSame(expected: 'John Doe', actual: $result['name']);
		$this->assertSame(expected: 'john@example.com', actual: $result['email']);
		$this->assertSame(expected: 'Developer', actual: $result['role']);
		$this->assertSame(expected: 'client-1', actual: $result['client']);
	}//end testBuildContactImportData()

	/**
	 * Test buildContactImportData without client ID.
	 *
	 * @return void
	 */
	public function testBuildContactImportDataNoClient(): void {
		$ncContact = ['FN' => 'Jane'];

		$result = $this->builder->buildContactImportData($ncContact, 'uid-456', null);

		$this->assertArrayNotHasKey(key: 'client', array: $result);
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

		$this->assertSame(expected: 'SomeCorp', actual: $result['name']);
		$this->assertSame(expected: 'organization', actual: $result['type']);
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

		$this->assertSame(expected: 'john@work.example', actual: $result['email']);
		$this->assertSame(expected: '+31611111111', actual: $result['phone']);

		$this->assertSame(
			expected: [
				['kind' => 'work', 'value' => 'john@work.example', 'primary' => true, 'verified' => false],
				['kind' => 'private', 'value' => 'john@home.example', 'primary' => false, 'verified' => false],
			],
			actual: $result['emails']
		);
		$this->assertSame(
			expected: [
				['kind' => 'mobile', 'value' => '+31611111111', 'primary' => true, 'verified' => false],
				['kind' => 'work', 'value' => '+31622222222', 'primary' => false, 'verified' => false],
			],
			actual: $result['phones']
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
			expected: [['kind' => 'other', 'value' => '+31699999999', 'primary' => true, 'verified' => false]],
			actual: $result['phones']
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
			expected: [
				[
					'network' => 'linkedin', 'handle' => '', 'url' => 'https://linkedin.com/in/jane',
					'verified' => false, 'followedByUs' => false, 'followsUs' => false,
				],
				['network' => 'mastodon', 'handle' => '@jane@mastodon.social', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
				['network' => 'other', 'handle' => 'janedoe', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
			],
			actual: $result['socialProfiles']
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

		$this->assertSame(expected: [], actual: $result['emails']);
		$this->assertSame(expected: [], actual: $result['phones']);
		$this->assertSame(expected: [], actual: $result['socialProfiles']);
		$this->assertArrayNotHasKey(key: 'email', array: $result);
		$this->assertArrayNotHasKey(key: 'phone', array: $result);
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

		$this->assertSame(expected: 'john@work.example', actual: $result['email']);
		$this->assertSame(
			expected: [['kind' => 'work', 'value' => 'john@work.example', 'primary' => true, 'verified' => false]],
			actual: $result['emails']
		);
	}//end testBuildContactImportDataTypedEmails()

	/**
	 * A nickname on the Nextcloud contact arrives as the preferred name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-a-person-carries-a-preferred-name-req-cpn-001
	 */
	public function testImportReadsTheNicknameAsThePreferredName(): void {
		$contact = $this->builder->buildContactImportData(['FN' => 'Johanna de Wit', 'NICKNAME' => ['Hanneke']], 'uid-1', null);
		$person = $this->builder->buildClientImportData(['FN' => 'Johannes Jansen', 'NICKNAME' => ' Jan '], 'uid-2');

		$this->assertSame(expected: 'Hanneke', actual: $contact['preferredName']);
		$this->assertSame(expected: 'Jan', actual: $person['preferredName']);
		$this->assertSame(expected: 'person', actual: $person['type']);
	}//end testImportReadsTheNicknameAsThePreferredName()

	/**
	 * A nickname removed in the Contacts app clears the preferred name; a
	 * contact that never carried one leaves the field out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-a-person-carries-a-preferred-name-req-cpn-001
	 */
	public function testImportClearsOrLeavesThePreferredName(): void {
		$cleared = $this->builder->buildContactImportData(['FN' => 'Johanna de Wit', 'NICKNAME' => ''], 'uid-1', null);
		$absent = $this->builder->buildContactImportData(['FN' => 'Johanna de Wit'], 'uid-1', null);

		$this->assertArrayHasKey(key: 'preferredName', array: $cleared);
		$this->assertSame(expected: '', actual: $cleared['preferredName']);
		$this->assertArrayNotHasKey(key: 'preferredName', array: $absent);
	}//end testImportClearsOrLeavesThePreferredName()

	/**
	 * An organisation takes no preferred name from its card.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-a-person-carries-a-preferred-name-req-cpn-001
	 */
	public function testImportGivesAnOrganisationNoPreferredName(): void {
		$organisation = $this->builder->buildClientImportData(['FN' => 'Test Corp', 'ORG' => 'Test Corp', 'NICKNAME' => 'TC'], 'uid-3');

		$this->assertSame(expected: 'organization', actual: $organisation['type']);
		$this->assertArrayNotHasKey(key: 'preferredName', array: $organisation);
	}//end testImportGivesAnOrganisationNoPreferredName()
	/**
	 * Array-shaped EMAIL, TEL and X-SOCIALPROFILE items are read without
	 * casting the array to a string, so the build raises no PHP warning.
	 *
	 * @return void
	 */
	public function testTypedItemsBuildWithoutWarnings(): void {
		$ncContact = [
			'FN' => 'Jane',
			'EMAIL' => [['type' => 'WORK', 'value' => 'jane@work.example'], 'jane@home.example'],
			'TEL' => [['type' => 'CELL', 'value' => '+31600000000']],
			'X-SOCIALPROFILE' => [['type' => 'linkedin', 'value' => 'https://linkedin.com/in/jane'], 'janedoe'],
		];

		$warnings = [];
		set_error_handler(
			static function (int $errno, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			}
		);
		try {
			$result = $this->builder->buildClientImportData(ncContact: $ncContact, uid: 'uid-warn');
		} finally {
			restore_error_handler();
		}

		$this->assertSame(expected: [], actual: $warnings);
		$this->assertSame(expected: ['jane@work.example', 'jane@home.example'], actual: array_column($result['emails'], 'value'));
		$this->assertSame(expected: ['+31600000000'], actual: array_column($result['phones'], 'value'));
		$this->assertCount(expectedCount: 2, haystack: $result['socialProfiles']);
	}//end testTypedItemsBuildWithoutWarnings()

	/**
	 * Golden: the network type is matched case-insensitively, an http URL is a url, an untyped
	 * item is `other`, and an empty value is dropped.
	 *
	 * @return void
	 */
	public function testSocialProfileEdgeCasesAreExact(): void {
		$result = $this->builder->buildClientImportData(
			[
				'FN' => 'Jane',
				'X-SOCIALPROFILE' => [
					['type' => 'LinkedIn', 'value' => 'http://linkedin.com/in/jane'],
					'janedoe',
					['type' => 'x', 'value' => ''],
				],
			],
			'uid-edge'
		);

		$this->assertSame(
			expected: [
				['network' => 'linkedin', 'handle' => '', 'url' => 'http://linkedin.com/in/jane', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
				['network' => 'other', 'handle' => 'janedoe', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
			],
			actual: $result['socialProfiles']
		);
	}//end testSocialProfileEdgeCasesAreExact()
}//end class
