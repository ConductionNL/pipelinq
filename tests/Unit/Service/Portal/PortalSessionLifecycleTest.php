<?php

/**
 * Unit tests for PortalSessionManager extension and revocation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Exact repository writes for extending and revoking sessions, and the
 * refusals for unknown or revoked ones.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalSessionManager
 * @uses   \OCA\Pipelinq\Service\Portal\PortalException
 * @uses   \OCA\Pipelinq\Service\Portal\PortalTokenService
 */
class PortalSessionLifecycleTest extends TestCase {
	/**
	 * Repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private PortalObjectRepository&MockObject $repository;

	/**
	 * Manager under test.
	 *
	 * @var PortalSessionManager
	 */
	private PortalSessionManager $manager;

	/**
	 * Build the manager with a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = $this->createMock(PortalObjectRepository::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): DateTime => new DateTime('2026-05-01T10:00:00+00:00')
		);

		$tokens = new PortalTokenService($this->createMock(ISecureRandom::class), $time);
		$this->manager = new PortalSessionManager($this->repository, $tokens, $time);
	}//end setUp()

	/**
	 * Extending moves the expiry by the TTL (at least one hour) and saves under the same id.
	 *
	 * @return void
	 */
	public function testExtendSessionSavesNewExpiry(): void {
		$this->repository->method('find')->with('crmPortalSession', 's-1')->willReturn(['accountId' => 'a']);
		$this->repository->expects($this->exactly(2))->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id): array {
				TestCase::assertSame('crmPortalSession', $schema);
				TestCase::assertSame('s-1', $id);
				return $data;
			}
		);

		$this->assertSame(
			['accountId' => 'a', 'expiresAt' => '2026-05-01T14:00:00+00:00'],
			$this->manager->extendSession('s-1', 4)
		);
		$this->assertSame(
			['accountId' => 'a', 'expiresAt' => '2026-05-01T11:00:00+00:00'],
			$this->manager->extendSession('s-1', 0)
		);
	}//end testExtendSessionSavesNewExpiry()

	/**
	 * Unknown and revoked sessions are not extended.
	 *
	 * @return void
	 */
	public function testExtendSessionRefusesUnknownOrRevoked(): void {
		$this->repository->method('find')->willReturnMap(
			[
				['crmPortalSession', 'gone', null],
				['crmPortalSession', 'revoked', ['revoked' => true]],
			]
		);
		$this->repository->expects($this->never())->method('save');

		$this->assertNull($this->manager->extendSession('gone'));
		$this->assertNull($this->manager->extendSession('revoked'));
	}//end testExtendSessionRefusesUnknownOrRevoked()

	/**
	 * The throwing variant returns the update, or refuses with 401.
	 *
	 * @return void
	 */
	public function testExtendSessionOrThrow(): void {
		$this->repository->method('find')->willReturnMap(
			[
				['crmPortalSession', 'live', ['accountId' => 'a']],
				['crmPortalSession', 'gone', null],
			]
		);
		$this->repository->method('save')->willReturnCallback(
			static fn (string $schema, array $data, ?string $id): array => $data + ['savedAs' => $id]
		);

		$this->assertSame(
			['accountId' => 'a', 'expiresAt' => '2026-05-01T18:00:00+00:00', 'savedAs' => 'live'],
			$this->manager->extendSessionOrThrow('live')
		);

		try {
			$this->manager->extendSessionOrThrow('gone');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(401, $e->getStatus());
			$this->assertSame('unauthenticated', $e->getErrorCode());
			$this->assertSame('Niet ingelogd.', $e->getMessage());
		}
	}//end testExtendSessionOrThrow()

	/**
	 * Revoking stamps the flag, time and reason; an unknown session is a no-op.
	 *
	 * @return void
	 */
	public function testRevokeSession(): void {
		$this->repository->method('find')->willReturnMap(
			[
				['crmPortalSession', 's-1', ['accountId' => 'a']],
				['crmPortalSession', 'gone', null],
			]
		);
		$this->repository->expects($this->once())->method('save')->with(
			'crmPortalSession',
			[
				'accountId'     => 'a',
				'revoked'       => true,
				'revokedAt'     => '2026-05-01T10:00:00+00:00',
				'revokedReason' => 'logout',
			],
			's-1'
		);

		$this->manager->revokeSession('s-1', 'logout');
		$this->manager->revokeSession('gone', 'logout');
	}//end testRevokeSession()

	/**
	 * Revoke-all skips already-revoked and id-less sessions and counts the rest.
	 *
	 * @return void
	 */
	public function testRevokeAllForAccountSkipsRevokedAndIdless(): void {
		$open    = ['@self' => ['id' => 's-open'], 'accountId' => 'a'];
		$revoked = ['@self' => ['id' => 's-old'], 'revoked' => true];
		$idless  = ['accountId' => 'a'];

		$this->repository->expects($this->once())->method('findAll')
			->with('crmPortalSession', ['accountId' => 'a'])
			->willReturn([$open, $revoked, $idless]);
		$this->repository->method('idOf')->willReturnMap([[$open, 's-open'], [$idless, null]]);
		$this->repository->method('find')->with('crmPortalSession', 's-open')->willReturn($open);
		$this->repository->expects($this->once())->method('save')->with(
			'crmPortalSession',
			$this->callback(static fn (array $data): bool => $data['revoked'] === true && $data['revokedReason'] === 'password-reset'),
			's-open'
		);

		$this->assertSame(1, $this->manager->revokeAllForAccount('a', 'password-reset'));
	}//end testRevokeAllForAccountSkipsRevokedAndIdless()
}//end class
