<?php

/**
 * Unit tests for ConsentWish.
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

use OCA\Pipelinq\Service\Consent\ConsentSubject;
use OCA\Pipelinq\Service\Consent\ConsentWish;
use PHPUnit\Framework\TestCase;

/**
 * A consent change as sent to the consent service and as stored locally.
 *
 * @covers \OCA\Pipelinq\Service\Consent\ConsentWish
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentSubject
 */
class ConsentWishTest extends TestCase {

	/**
	 * The subject the wish is about.
	 *
	 * @return ConsentSubject
	 */
	private function subject(): ConsentSubject {
		return new ConsentSubject(contactId: 'c-1', channel: 'email', address: 'a@example.org');

	}//end subject()

	/**
	 * An opt-in carries its lawful basis to the consent service.
	 *
	 * @return void
	 */
	public function testAnOptInRequestCarriesTheLawfulBasis(): void {
		$wish = new ConsentWish(state: 'opted-in', source: 'form', evidence: 'Ticked the box', legalBasis: 'consent');

		$this->assertSame(
			[
				'address' => 'a@example.org',
				'state' => 'opted-in',
				'scope' => 'channel',
				'channel' => 'email',
				'contactRef' => 'c-1',
				'source' => 'form',
				'evidence' => ['text' => 'Ticked the box'],
				'lawfulBasis' => 'consent',
			],
			$wish->changeRequest(subject: $this->subject())
		);
	}//end testAnOptInRequestCarriesTheLawfulBasis()

	/**
	 * Any other state is sent without a lawful basis.
	 *
	 * @return void
	 */
	public function testAnOptOutRequestHasNoLawfulBasis(): void {
		$wish = new ConsentWish(state: 'opted-out', source: 'link', evidence: 'Clicked unsubscribe', legalBasis: 'consent');

		$request = $wish->changeRequest(subject: $this->subject());

		$this->assertArrayNotHasKey('lawfulBasis', $request);
		$this->assertSame('opted-out', $request['state']);
	}//end testAnOptOutRequestHasNoLawfulBasis()

	/**
	 * The stored row keeps the given basis, the subject and the evidence,
	 * stamped with a UTC microsecond timestamp.
	 *
	 * @return void
	 */
	public function testTheStoredRowKeepsTheGivenBasis(): void {
		$wish = new ConsentWish(state: 'opted-in', source: 'form', evidence: 'Ticked', legalBasis: 'contract');

		$row = $wish->storedRow(subject: $this->subject());

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $row['recordedAt']);
		unset($row['recordedAt']);
		$this->assertSame(
			[
				'contactId' => 'c-1',
				'channel' => 'email',
				'state' => 'opted-in',
				'source' => 'form',
				'evidence' => 'Ticked',
				'legalBasis' => 'contract',
			],
			$row
		);
	}//end testTheStoredRowKeepsTheGivenBasis()

	/**
	 * An empty basis is stored as consent.
	 *
	 * @return void
	 */
	public function testAnEmptyBasisIsStoredAsConsent(): void {
		$wish = new ConsentWish(state: 'opted-out', source: 'link', evidence: 'x', legalBasis: '');

		$this->assertSame('consent', $wish->storedRow(subject: $this->subject())['legalBasis']);
	}//end testAnEmptyBasisIsStoredAsConsent()
}//end class
