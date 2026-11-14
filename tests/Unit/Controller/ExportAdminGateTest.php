<?php

/**
 * Unit tests for ExportAdminGate.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ExportActionResponse;
use OCA\Pipelinq\Controller\ExportAdminGate;
use OCA\Pipelinq\Kernel\Contracts\ExportAccessPolicyInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ExportAdminGate and ExportActionResponse guard and answer the data-export endpoints.
 */
class ExportAdminGateTest extends TestCase {

	/**
	 * A translator that returns the text.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

	/**
	 * A gate for a uid (or nobody) and a policy granting export admin to the listed uids.
	 *
	 * @param string|null  $uid    The signed-in uid.
	 * @param list<string> $admins The export admins.
	 *
	 * @return ExportAdminGate
	 */
	private function gate(?string $uid, array $admins): ExportAdminGate {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ExportAccessPolicyInterface::class);
		$policy->method('isExportAdmin')->willReturnCallback(static fn (string $userId): bool => in_array($userId, $admins, true));

		return new ExportAdminGate(userSession: $session, policy: $policy);
	}//end gate()

	/**
	 * An export admin passes; others get 403; anonymous callers 401.
	 *
	 * @return void
	 */
	public function testTheGate(): void {
		self::assertNull($this->gate('anna', ['anna'])->refusal(l10n: $this->l10n()));
		self::assertSame('anna', $this->gate('anna', ['anna'])->userId());

		$forbidden = $this->gate('bram', ['anna'])->refusal(l10n: $this->l10n());
		self::assertSame(Http::STATUS_FORBIDDEN, $forbidden->getStatus());
		self::assertSame(['error' => 'Insufficient permissions'], $forbidden->getData());

		$anonymous = $this->gate(null, ['anna']);
		self::assertSame('', $anonymous->userId());
		$unauthorized = $anonymous->refusal(l10n: $this->l10n());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $unauthorized->getStatus());
		self::assertSame(['error' => 'Authentication required'], $unauthorized->getData());
	}//end testTheGate()

	/**
	 * A successful action is answered as JSON.
	 *
	 * @return void
	 */
	public function testASuccessfulAction(): void {
		$response = (new ExportActionResponse(l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class)))->respond(
			action: static fn (): array => ['jobId' => 'j-1'],
			label: 'Export start'
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['jobId' => 'j-1'], $response->getData());
	}//end testASuccessfulAction()

	/**
	 * Each OCS exception maps to its status and keeps its message.
	 *
	 * @return array<string, array{0: \Throwable, 1: int}>
	 */
	public static function failures(): array {
		return [
			'not found' => [new OCSNotFoundException('No such export'), Http::STATUS_NOT_FOUND],
			'forbidden' => [new OCSForbiddenException('Not yours'), Http::STATUS_FORBIDDEN],
			'bad request' => [new OCSBadRequestException('Unknown format'), Http::STATUS_UNPROCESSABLE_ENTITY],
		];
	}//end failures()

	/**
	 * A known failure is answered with its own status and message.
	 *
	 * @param \Throwable $failure The thrown failure.
	 * @param int        $status  The status it maps to.
	 *
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testAKnownFailure(\Throwable $failure, int $status): void {
		$response = (new ExportActionResponse(l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class)))->respond(
			action: static function () use ($failure): array {
				throw $failure;
			},
			label: 'Export'
		);

		self::assertSame($status, $response->getStatus());
		self::assertSame(['error' => $failure->getMessage()], $response->getData());
	}//end testAKnownFailure()

	/**
	 * Any other failure is logged under the label and answered as a generic 500.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsLoggedAndHidden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Export download failed', ['exception' => 'disk full']);

		$response = (new ExportActionResponse(l10n: $this->l10n(), logger: $logger))->respond(
			action: static function (): array {
				throw new RuntimeException('disk full');
			},
			label: 'Export download'
		);

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'An unexpected error occurred'], $response->getData());
	}//end testAnUnexpectedFailureIsLoggedAndHidden()
}//end class
