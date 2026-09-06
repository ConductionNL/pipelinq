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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ContactVcardPropertyBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for ContactVcardPropertyBuilder.
 */
class ContactVcardPropertyBuilderTest extends TestCase {
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
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$container = $this->createMock(ContainerInterface::class);

		$this->builder = new ContactVcardPropertyBuilder($appConfig,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	/**
	 * Test buildProperties returns FN for client.
	 *
	 * @return void
	 */
	public function testBuildPropertiesReturnsFnForClient(): void {
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
	public function testBuildPropertiesIncludesPhone(): void {
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
	public function testBuildPropertiesIncludesClientWebsiteAndNotes(): void {
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
	public function testBuildPropertiesIncludesContactRole(): void {
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
	public function testBuildPropertiesDefaultsToUnknown(): void {
		$result = $this->builder->buildProperties([], 'client');

		$this->assertSame('Unknown', $result['FN']);
	}//end testBuildPropertiesDefaultsToUnknown()

	/**
	 * A typed `emails[]`/`phones[]` array wins over the legacy scalar
	 * `email`/`phone` field, and is written as the multi-value `[{value,
	 * type}, ...]` shape `AddressBookImpl::createOrUpdate()` expects, with
	 * kind mapped to the matching vCard TYPE token.
	 *
	 * @return void
	 */
	public function testBuildPropertiesUsesTypedEmailsAndPhonesOverLegacyScalar(): void {
		$result = $this->builder->buildProperties(
			[
				'name' => 'Jane',
				'email' => 'stale@example.com',
				'phone' => '+31600000000',
				'emails' => [
					['kind' => 'work', 'value' => 'jane@work.example', 'primary' => true, 'verified' => false],
					['kind' => 'private', 'value' => 'jane@home.example', 'primary' => false, 'verified' => false],
				],
				'phones' => [
					['kind' => 'mobile', 'value' => '+31611111111', 'primary' => true, 'verified' => false],
				],
			],
			'contact'
		);

		$this->assertSame(
			[
				['value' => 'jane@work.example', 'type' => 'WORK'],
				['value' => 'jane@home.example', 'type' => 'HOME'],
			],
			$result['EMAIL']
		);
		$this->assertSame(
			[['value' => '+31611111111', 'type' => 'CELL']],
			$result['TEL']
		);
	}//end testBuildPropertiesUsesTypedEmailsAndPhonesOverLegacyScalar()

	/**
	 * An empty `emails[]`/`phones[]` array falls back to the legacy scalar
	 * field, so an object not yet carrying typed channels still syncs.
	 *
	 * @return void
	 */
	public function testBuildPropertiesFallsBackToScalarWhenArraysEmpty(): void {
		$result = $this->builder->buildProperties(
			['name' => 'Jane', 'email' => 'jane@example.com', 'phone' => '+31600000000', 'emails' => [], 'phones' => []],
			'contact'
		);

		$this->assertSame('jane@example.com', $result['EMAIL']);
		$this->assertSame('+31600000000', $result['TEL']);
	}//end testBuildPropertiesFallsBackToScalarWhenArraysEmpty()

	/**
	 * An entry with a `kind` that has no vCard TYPE mapping (e.g. "other")
	 * omits the TYPE parameter rather than guessing one.
	 *
	 * @return void
	 */
	public function testBuildPropertiesOmitsTypeForUnmappedKind(): void {
		$result = $this->builder->buildProperties(
			['name' => 'Jane', 'emails' => [['kind' => 'other', 'value' => 'jane@example.com', 'primary' => true, 'verified' => false]]],
			'contact'
		);

		$this->assertSame([['value' => 'jane@example.com']], $result['EMAIL']);
	}//end testBuildPropertiesOmitsTypeForUnmappedKind()

	/**
	 * `socialProfiles[]` builds X-SOCIALPROFILE entries, preferring `url`
	 * over `handle` and carrying the network name verbatim as TYPE.
	 *
	 * @return void
	 */
	public function testBuildPropertiesIncludesSocialProfiles(): void {
		$result = $this->builder->buildProperties(
			[
				'name' => 'Jane',
				'socialProfiles' => [
					['network' => 'linkedin', 'handle' => 'jane', 'url' => 'https://linkedin.com/in/jane', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
					['network' => 'mastodon', 'handle' => '@jane@mastodon.social', 'url' => '', 'verified' => false, 'followedByUs' => false, 'followsUs' => false],
				],
			],
			'contact'
		);

		$this->assertSame(
			[
				['value' => 'https://linkedin.com/in/jane', 'type' => 'linkedin'],
				['value' => '@jane@mastodon.social', 'type' => 'mastodon'],
			],
			$result['X-SOCIALPROFILE']
		);
	}//end testBuildPropertiesIncludesSocialProfiles()

	/**
	 * No `socialProfiles[]` at all omits the X-SOCIALPROFILE key entirely
	 * (never an empty array), matching `AddressBookImpl::createOrUpdate()`'s
	 * "no key means untouched" contract.
	 *
	 * @return void
	 */
	public function testBuildPropertiesOmitsSocialProfileKeyWhenAbsent(): void {
		$result = $this->builder->buildProperties(['name' => 'Jane'], 'contact');

		$this->assertArrayNotHasKey('X-SOCIALPROFILE', $result);
	}//end testBuildPropertiesOmitsSocialProfileKeyWhenAbsent()
}//end class
