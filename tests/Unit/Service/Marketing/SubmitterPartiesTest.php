<?php

/**
 * Unit tests for SubmitterParties.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Kernel\Contracts\ListObjectStoreInterface;
use OCA\Pipelinq\Service\Marketing\SubmitterParties;
use PHPUnit\Framework\TestCase;

/**
 * A match by the first address that finds one wins; otherwise the record is created.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\SubmitterParties
 */
class SubmitterPartiesTest extends TestCase {

	/**
	 * @return void
	 */
	public function testContactMatchedByTheFirstAddressThatFindsOne(): void {
		$store = $this->createMock(ListObjectStoreInterface::class);
		$store->method('findAll')->willReturnCallback(
			static fn (string $schemaSlug, array $filters): array => ($filters['email'] === 'b@x.nl' ? [['id' => 'c-2']] : [])
		);
		$store->method('idOf')->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));
		$store->expects($this->never())->method('save');

		$this->assertSame('c-2', (new SubmitterParties(store: $store))->contactId(candidates: ['a@x.nl', 'b@x.nl'], newContact: []));
	}//end testContactMatchedByTheFirstAddressThatFindsOne()

	/**
	 * @return void
	 */
	public function testContactAndClientCreatedWhenNoneMatches(): void {
		$store = $this->createMock(ListObjectStoreInterface::class);
		$store->method('findAll')->willReturn([]);
		$store->method('idOf')->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));
		$saved = [];
		$store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload) use (&$saved): array {
				$saved[] = [$schemaSlug, $payload];
				return ['id' => $schemaSlug . '-new'];
			}
		);
		$parties = new SubmitterParties(store: $store);

		$this->assertSame('contact-new', $parties->contactId(candidates: ['a@x.nl'], newContact: ['name' => 'A']));
		$this->assertSame('client-new', $parties->clientId(organisation: 'Acme', notes: 'Note.'));
		$this->assertSame(
			[
				['contact', ['name' => 'A']],
				['client', ['name' => 'Acme', 'type' => 'organization', 'notes' => 'Note.']],
			],
			$saved
		);
	}//end testContactAndClientCreatedWhenNoneMatches()

	/**
	 * @return void
	 */
	public function testUtmBlockHasTheFiveKeys(): void {
		$store = $this->createMock(ListObjectStoreInterface::class);

		$this->assertSame(
			['campaign' => 'c', 'source' => '', 'medium' => 'm', 'term' => '', 'content' => ''],
			(new SubmitterParties(store: $store))->utmBlock(utm: ['campaign' => ' c ', 'medium' => 'm', 'other' => 'x'])
		);
	}//end testUtmBlockHasTheFiveKeys()
}//end class
