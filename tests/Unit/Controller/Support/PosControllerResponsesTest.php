<?php

/**
 * Behavioural tests for the PosControllerResponses trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller\Support;

use OCA\Pipelinq\Controller\Support\PosControllerResponses;
use OCA\Pipelinq\Controller\Support\PosJsonAction;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The session user's id or a 401, and actions answered through PosResponses.
 *
 * @covers \OCA\Pipelinq\Controller\Support\PosControllerResponses
 */
class PosControllerResponsesTest extends TestCase {

	/**
	 * Build a host for the given session user.
	 *
	 * @param IUser|null      $user   The session user.
	 * @param LoggerInterface $logger The logger.
	 * @param TestCase        $test   The test (for stubs).
	 *
	 * @return PosControllerResponsesHost The host.
	 */
	public static function host(?IUser $user, LoggerInterface $logger, TestCase $test): PosControllerResponsesHost {
		$session = $test->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $test->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PosControllerResponsesHost($session, $l10n, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testRequireUserId(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$logger = $this->createStub(LoggerInterface::class);

		$this->assertSame('u1', self::host($user, $logger, $this)->userId());
		$anonymous = self::host(null, $logger, $this)->userId();
		$this->assertInstanceOf(JSONResponse::class, $anonymous);
		$this->assertSame(401, $anonymous->getStatus());
	}//end testRequireUserId()
}//end class

/**
 * A named host, so the action log label is a predictable short class name.
 */
final class PosControllerResponsesHost {
	use PosControllerResponses;
	use PosJsonAction;

	/**
	 * @param IUserSession    $userSession The session.
	 * @param IL10N           $l10n        The translator.
	 * @param LoggerInterface $logger      The logger.
	 */
	public function __construct(
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return string|JSONResponse
	 */
	public function userId(): string|JSONResponse {
		return $this->requireUserId();
	}

	/**
	 * @param callable $action The action.
	 * @return JSONResponse
	 */
	public function act(callable $action): JSONResponse {
		return $this->run(action: $action, label: 'act');
	}
}//end class
