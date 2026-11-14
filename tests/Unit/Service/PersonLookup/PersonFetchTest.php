<?php

/**
 * Unit tests for PersonFetch: the copies it makes and when a person is secret.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\PersonLookup
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PersonLookup;

use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\PersonLookup\PersonFetch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One BRP fetch's outcome.
 */
#[CoversClass(PersonFetch::class)]
#[UsesClass(LookupResult::class)]
class PersonFetchTest extends TestCase {

	/**
	 * A failed fetch from the cache.
	 *
	 * @return PersonFetch
	 */
	private function fetch(): PersonFetch {
		return new PersonFetch(
			person: null,
			result: new LookupResult(outcome: 'not-found', action: 'brp-lookup-cached', responseCode: 404, correlationId: 'corr-1'),
			fromCache: true,
			durationMs: 12.5
		);
	}//end fetch()

	/**
	 * succeeded sets the person and a succeeded result that keeps the response code and correlation id.
	 *
	 * @return void
	 */
	public function testSucceededKeepsTheCodeAndCorrelation(): void {
		$original = $this->fetch();

		$done = $original->succeeded(person: ['bsn' => '999993653']);

		$this->assertNotSame($original, $done);
		$this->assertSame(['bsn' => '999993653'], $done->person);
		$this->assertSame('succeeded', $done->result->outcome);
		$this->assertSame('brp-lookup-executed', $done->result->action);
		$this->assertSame(404, $done->result->responseCode);
		$this->assertSame('corr-1', $done->result->correlationId);
		$this->assertTrue($done->fromCache);
		$this->assertSame(12.5, $done->durationMs);
		$this->assertNull($original->person, 'the original is unchanged');
	}//end testSucceededKeepsTheCodeAndCorrelation()

	/**
	 * withResult and withPerson replace one part and keep the rest.
	 *
	 * @return void
	 */
	public function testWithResultAndWithPersonReplaceOnePart(): void {
		$original = $this->fetch();
		$result   = new LookupResult(outcome: 'failed');

		$withResult = $original->withResult(result: $result);
		$withPerson = $original->withPerson(person: ['naam' => 'Jan']);

		$this->assertSame($result, $withResult->result);
		$this->assertNull($withResult->person);
		$this->assertSame(['naam' => 'Jan'], $withPerson->person);
		$this->assertSame($original->result, $withPerson->result);
		$this->assertTrue($withPerson->fromCache);
		$this->assertSame(12.5, $withPerson->durationMs);
		$this->assertNull($withPerson->withPerson(person: null)->person);
	}//end testWithResultAndWithPersonReplaceOnePart()

	/**
	 * A person is secret only when the indication reads 1.
	 *
	 * @return void
	 */
	public function testIsSecretOnlyForIndicationOne(): void {
		$base = $this->fetch();

		$this->assertFalse($base->isSecret(), 'no person');
		$this->assertFalse($base->withPerson(person: [])->isSecret());
		$this->assertFalse($base->withPerson(person: ['indicationSecret' => 0])->isSecret());
		$this->assertFalse($base->withPerson(person: ['indicationSecret' => '7'])->isSecret());
		$this->assertTrue($base->withPerson(person: ['indicationSecret' => '1'])->isSecret());
		$this->assertTrue($base->withPerson(person: ['indicationSecret' => 1])->isSecret());
	}//end testIsSecretOnlyForIndicationOne()
}//end class
