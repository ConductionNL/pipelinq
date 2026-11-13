<?php

/**
 * Unit tests for ConsentRecordKey.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Consent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Consent;

use OCA\Pipelinq\Service\Consent\ConsentRecordKey;
use PHPUnit\Framework\TestCase;

/**
 * The key that finds one stored consent row.
 *
 * @covers \OCA\Pipelinq\Service\Consent\ConsentRecordKey
 */
class ConsentRecordKeyTest extends TestCase {

	/**
	 * The register filters carry the contact, channel, register and schema.
	 *
	 * @return void
	 */
	public function testFiltersNameTheContactChannelAndSchema(): void {
		$key = new ConsentRecordKey(contactId: 'c-1', channel: 'email', listId: 'l-1');

		$this->assertSame(
			['contactId' => 'c-1', 'channel' => 'email', 'register' => 'pipelinq', 'schema' => 'consent'],
			$key->filters(register: 'pipelinq', schema: 'consent')
		);
	}//end testFiltersNameTheContactChannelAndSchema()

	/**
	 * A channel-wide key matches the row of its contact and channel with no
	 * list, comparing the stored channel case-insensitively.
	 *
	 * @return void
	 */
	public function testAChannelWideKeyMatchesOnlyAListlessRow(): void {
		$key = new ConsentRecordKey(contactId: 'c-1', channel: 'email', listId: null);

		$this->assertTrue($key->matches(['contactId' => 'c-1', 'channel' => 'EMAIL']));
		$this->assertTrue($key->matches(['contactId' => 'c-1', 'channel' => 'email', 'listId' => '']));
		$this->assertFalse($key->matches(['contactId' => 'c-1', 'channel' => 'email', 'listId' => 'l-1']));
	}//end testAChannelWideKeyMatchesOnlyAListlessRow()

	/**
	 * A list key matches only the row of that list.
	 *
	 * @return void
	 */
	public function testAListKeyMatchesOnlyItsList(): void {
		$key = new ConsentRecordKey(contactId: 'c-1', channel: 'email', listId: 'l-1');

		$this->assertTrue($key->matches(['contactId' => 'c-1', 'channel' => 'email', 'listId' => 'l-1']));
		$this->assertFalse($key->matches(['contactId' => 'c-1', 'channel' => 'email', 'listId' => 'l-2']));
		$this->assertFalse($key->matches(['contactId' => 'c-1', 'channel' => 'email']));
	}//end testAListKeyMatchesOnlyItsList()

	/**
	 * An empty row, another contact or another channel never match.
	 *
	 * @return void
	 */
	public function testOtherRowsNeverMatch(): void {
		$key = new ConsentRecordKey(contactId: 'c-1', channel: 'email', listId: null);

		$this->assertFalse($key->matches([]));
		$this->assertFalse($key->matches(['contactId' => 'c-2', 'channel' => 'email']));
		$this->assertFalse($key->matches(['contactId' => 'c-1', 'channel' => 'sms']));
		$this->assertFalse($key->matches(['channel' => 'email']));
	}//end testOtherRowsNeverMatch()
}//end class
