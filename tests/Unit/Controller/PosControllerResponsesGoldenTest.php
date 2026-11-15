<?php

/**
 * Golden tests for the login check and the error mapping the POS controllers share.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/pos-cash-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CashShiftController;
use OCA\Pipelinq\Controller\PosBookkeepingController;
use OCA\Pipelinq\Controller\PosCustomerController;
use OCA\Pipelinq\Controller\PosReceiptController;
use OCA\Pipelinq\Controller\PosRefundController;
use OCA\Pipelinq\Controller\PosRoleController;
use OCA\Pipelinq\Controller\PosStaffController;
use OCA\Pipelinq\Controller\PosTransactionController;
use OCA\Pipelinq\Controller\ProductCatalogController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Every outcome of requireUserId() and run() in each POS controller.
 */
class PosControllerResponsesGoldenTest extends TestCase {
	/**
	 * The controllers whose login check is the plain authenticated-user check.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function loginProvider(): array {
		$cases = [];
		foreach ([CashShiftController::class, PosRefundController::class, PosRoleController::class, PosStaffController::class, PosBookkeepingController::class, PosCustomerController::class, PosReceiptController::class, PosTransactionController::class] as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end loginProvider()

	/**
	 * Without a user the action is refused with 401; with one its UID is returned.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider loginProvider
	 */
	public function testLoginCheck(string $class): void {
		$response = $this->call(controller: $this->controller(class: $class, user: false), method: 'requireUserId', args: []);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame([401, ['error' => 'T:Authentication required']], [$response->getStatus(), $response->getData()]);

		$this->assertSame('cashier-1', $this->call(controller: $this->controller(class: $class, user: true), method: 'requireUserId', args: []));
	}//end testLoginCheck()

	/**
	 * The controllers, the extra run() arguments and the success envelope and status.
	 *
	 * @return array<string, array{0: class-string, 1: array<int, string>, 2: callable(array<string, int>): array<string, mixed>, 3: int}>
	 */
	public static function runProvider(): array {
		$plain = static fn (array $data): array => $data;
		return [
			'cash shifts' => [CashShiftController::class, [], $plain, 200],
			'roles' => [PosRoleController::class, [], $plain, 200],
			'staff' => [PosStaffController::class, [], $plain, 200],
			'catalogue' => [ProductCatalogController::class, [], $plain, 200],
			'refunds' => [PosRefundController::class, [], static fn (array $data): array => ['refund' => $data], 200],
			'bookkeeping' => [PosBookkeepingController::class, [], $plain, 202],
			'transactions' => [PosTransactionController::class, [], static fn (array $data): array => ['transaction' => $data], 200],
			'transactions keyed' => [PosTransactionController::class, ['report'], static fn (array $data): array => ['report' => $data], 200],
			'customers' => [PosCustomerController::class, [], static fn (array $data): array => ['transaction' => $data], 200],
			'customers keyed' => [PosCustomerController::class, ['customers'], static fn (array $data): array => ['customers' => $data], 200],
			'receipts' => [PosReceiptController::class, ['receipt'], static fn (array $data): array => ['receipt' => $data], 200],
		];
	}//end runProvider()

	/**
	 * A successful action is answered in the controller's envelope; each
	 * refusal of the service maps to its status, and any other failure is
	 * logged under the controller's name and answered with a generic 500.
	 *
	 * @param class-string            $class    The controller.
	 * @param array<int, string>      $extra    Extra run() arguments.
	 * @param callable(array): array  $envelope The success envelope.
	 * @param int                     $status   The success status.
	 *
	 * @return void
	 *
	 * @dataProvider runProvider
	 */
	public function testRunOutcomes(string $class, array $extra, callable $envelope, int $status): void {
		$controller = $this->controller(class: $class, user: true);
		$response = $this->call(controller: $controller, method: 'run', args: array_merge([static fn (): array => ['id' => 7], 'open'], $extra));
		$this->assertSame([$status, $envelope(['id' => 7])], [$response->getStatus(), $response->getData()]);

		$refusals = [
			[new OCSNotFoundException('No such shift'), 404],
			[new OCSForbiddenException('Managers only'), 403],
			[new OCSBadRequestException('Already closed'), 422],
		];
		foreach ($refusals as [$error, $code]) {
			$response = $this->call(controller: $controller, method: 'run', args: array_merge([static function () use ($error): array {
				throw $error;
			}, 'open'], $extra));
			$this->assertSame([$code, ['error' => $error->getMessage()]], [$response->getStatus(), $response->getData()]);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$short = (new ReflectionClass($class))->getShortName();
		$logger->expects($this->once())->method('error')->with($short . '::open failed', ['exception' => 'disk full']);
		$controller = $this->controller(class: $class, user: true, logger: $logger);
		$response = $this->call(controller: $controller, method: 'run', args: array_merge([static function (): array {
			throw new RuntimeException('disk full');
		}, 'open'], $extra));
		$this->assertSame([500, ['error' => 'T:An unexpected error occurred']], [$response->getStatus(), $response->getData()]);
	}//end testRunOutcomes()

	/**
	 * Call a private controller method.
	 *
	 * @param object             $controller The controller.
	 * @param string             $method     The method.
	 * @param array<int, mixed>  $args       The arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(object $controller, string $method, array $args): mixed {
		$reflection = new ReflectionMethod($controller, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($controller, $args);
	}//end call()

	/**
	 * A controller with only the session, the translator and the logger set.
	 *
	 * @param class-string         $class  The controller.
	 * @param bool                 $user   Whether a user is logged in.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return object The controller.
	 */
	private function controller(string $class, bool $user, ?LoggerInterface $logger = null): object {
		$reflection = new ReflectionClass($class);
		$controller = $reflection->newInstanceWithoutConstructor();

		$session = $this->createMock(IUserSession::class);
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('cashier-1');
		$session->method('getUser')->willReturn($user === true ? $account : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		foreach (['userSession' => $session, 'l10n' => $l10n, 'logger' => $logger ?? $this->createMock(LoggerInterface::class)] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($controller, $value);
		}

		return $controller;
	}//end controller()
}//end class
