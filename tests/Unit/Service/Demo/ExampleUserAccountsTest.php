<?php

/**
 * Unit tests for ExampleUserAccounts.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\Pipelinq\Service\Demo\ExampleUserAccounts;
use PHPUnit\Framework\TestCase;

/**
 * User fields point at real accounts, the acting user, or are left out.
 */
class ExampleUserAccountsTest extends TestCase {

	/**
	 * The schema properties used by the tests.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PROPERTIES = [
		'owner'    => ['type' => 'string', 'format' => 'user'],
		'watchers' => ['type' => 'array', 'items' => ['referenceType' => 'nextcloud-user']],
		'title'    => ['type' => 'string'],
	];

	/**
	 * Accounts where only `real` exists.
	 *
	 * @param string|null $actingUid Who loads the examples.
	 *
	 * @return ExampleUserAccounts
	 */
	private function accounts(?string $actingUid): ExampleUserAccounts {
		return new ExampleUserAccounts(userExists: static fn (string $uid): bool => $uid === 'real', actingUid: $actingUid);
	}//end accounts()

	/**
	 * A demo name becomes the acting user; a real one and other fields stay.
	 *
	 * @return void
	 */
	public function testDemoNamesBecomeTheActingUser(): void {
		$object = ['owner' => 'jan.smit', 'watchers' => ['real', 'emma', 'piet'], 'title' => 'jan.smit'];

		$result = $this->accounts(actingUid: 'admin')->assignFields(object: $object, properties: self::PROPERTIES);

		self::assertSame(['owner' => 'admin', 'watchers' => ['real', 'admin'], 'title' => 'jan.smit'], $result);
	}//end testDemoNamesBecomeTheActingUser()

	/**
	 * Without an acting user a demo name is left out.
	 *
	 * @return void
	 */
	public function testDemoNamesAreLeftOutWithoutActingUser(): void {
		$object = ['owner' => 'jan.smit', 'watchers' => ['emma', 'real']];

		$result = $this->accounts(actingUid: null)->assignFields(object: $object, properties: self::PROPERTIES);

		self::assertSame(['watchers' => ['real']], $result);
	}//end testDemoNamesAreLeftOutWithoutActingUser()

	/**
	 * Real users and empty values stay as they are.
	 *
	 * @return void
	 */
	public function testRealAndEmptyValuesStay(): void {
		$object = ['owner' => 'real'];
		self::assertSame($object, $this->accounts(actingUid: 'admin')->assignFields(object: $object, properties: self::PROPERTIES));

		$empty = ['owner' => ''];
		self::assertSame($empty, $this->accounts(actingUid: 'admin')->assignFields(object: $empty, properties: self::PROPERTIES));
	}//end testRealAndEmptyValuesStay()
}//end class
