<?php

/**
 * Golden test for PortalScopeResolver::classify — own, delegated and not visible.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
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

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins every outcome of classify() over a fixed resolved scope: which objects are
 * own (null), which are delegated (the grantor), and which are not visible (false).
 */
class PortalScopeClassifyGoldenTest extends TestCase {
	/**
	 * A resolved scope: own contact and client, and two delegations sharing one client.
	 *
	 * @var array<string, mixed>
	 */
	private const RESOLVED = [
		'ownContactIds' => ['c-own'],
		'ownClientIds' => ['o-own'],
		'delegated' => [
			['from' => 'acct-first', 'contactIds' => ['c-first'], 'clientIds' => ['o-shared']],
			['from' => 'acct-second', 'contactIds' => ['c-second', 'c-own'], 'clientIds' => ['o-shared', 'o-second']],
		],
	];

	/**
	 * Each object (contact id, client id) and its classification.
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: string|null|false}>
	 */
	public static function objects(): array {
		return [
			'own contact' => ['c-own', null, null],
			'own client' => [null, 'o-own', null],
			'own contact beats a delegation' => ['c-own', 'o-second', null],
			'own client with a stranger contact' => ['c-stranger', 'o-own', null],
			'first delegation by contact' => ['c-first', null, 'acct-first'],
			'first matching delegation wins' => [null, 'o-shared', 'acct-first'],
			'second delegation by contact' => ['c-second', 'o-stranger', 'acct-second'],
			'second delegation by client' => ['c-stranger', 'o-second', 'acct-second'],
			'stranger' => ['c-stranger', 'o-stranger', false],
			'nothing known' => [null, null, false],
			'empty strings are not ids in scope' => ['', '', false],
			'ids are compared strictly' => ['C-OWN', 'O-OWN', false],
		];
	}//end objects()

	/**
	 * Every object classifies as pinned.
	 *
	 * @param string|null       $contactId The object's contact id.
	 * @param string|null       $clientId  The object's client id.
	 * @param string|null|false $expected  The classification.
	 *
	 * @return void
	 *
	 * @dataProvider objects
	 */
	public function testClassify(?string $contactId, ?string $clientId, string|null|false $expected): void {
		$this->assertSame($expected, $this->resolver()->classify(self::RESOLVED, $contactId, $clientId));
	}//end testClassify()

	/**
	 * Without own ids or delegations nothing is visible.
	 *
	 * @return void
	 */
	public function testEmptyScopeShowsNothing(): void {
		$empty = ['ownContactIds' => [], 'ownClientIds' => [], 'delegated' => []];
		$this->assertFalse($this->resolver()->classify($empty, 'c-own', 'o-own'));
	}//end testEmptyScopeShowsNothing()

	/**
	 * A resolver; classify() uses none of its collaborators.
	 *
	 * @return PortalScopeResolver
	 */
	private function resolver(): PortalScopeResolver {
		return new PortalScopeResolver(
			$this->createMock(PortalObjectRepository::class),
			$this->createMock(PortalDelegationService::class)
		);
	}//end resolver()
}//end class
