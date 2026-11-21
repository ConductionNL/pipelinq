<?php

/**
 * Characterisation tests for the PortalController signed-link endpoints,
 * the booking-body validation and the bookable-services projection.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\Controller\PortalController;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every allowed and refused outcome of reschedule(), cancel(),
 * openSignedLink(), the book() body validation and services().
 */
class PortalControllerLinkGoldenTest extends TestCase {

	/**
	 * The in-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The booking service mock of the last build.
	 *
	 * @var BookingService&MockObject
	 */
	private BookingService&MockObject $bookings;

	/**
	 * Logged errors and warnings.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params   Request params.
	 * @param mixed                $findAll  What findAll() answers, or a Throwable.
	 * @param int                  $time     The clock.
	 *
	 * @return PortalController
	 */
	private function controller(array $params = [], mixed $findAll = [], int $time = 1700000000): PortalController {
		$this->config += ['register' => 'reg-1', 'service_schema' => 'svc-schema', 'booking_schema' => 'bk-schema'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default ?? '')
		);
		$this->bookings = $this->createMock(BookingService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$objects = new class($findAll) {
			/**
			 * Constructor.
			 *
			 * @param mixed $findAll The answer.
			 */
			public function __construct(private mixed $findAll) {
			}

			/**
			 * Find all.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				if ($this->findAll instanceof \Throwable) {
					throw $this->findAll;
				}

				return $this->findAll;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$time = $this->createConfiguredMock(ITimeFactory::class, ['getTime' => $time]);
		$random = $this->createConfiguredMock(ISecureRandom::class, ['generate' => str_repeat('K', 64)]);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		return new PortalController(
			$request,
			$this->bookings,
			$this->createMock(AppointmentDepositService::class),
			$appConfig,
			$container,
			$this->createConfiguredMock(IAppManager::class, ['getInstalledApps' => ['openregister']]),
			$this->createMock(IURLGenerator::class),
			$time,
			$random,
			$logger
		);
	}//end controller()

	/**
	 * Status and body of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * A token signed with the controller's key over any payload.
	 *
	 * @param string $json The payload JSON.
	 *
	 * @return string
	 */
	private function forge(string $json): string {
		$this->controller()->signLink('x', 'y');
		$encode = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
		$encoded = $encode($json);
		$key = '';
		foreach ($this->config as $name => $value) {
			if (strlen($value) === 64 && str_contains($name, 'key') === true) {
				$key = $value;
			}
		}

		return $encoded . '.' . $encode(hash_hmac('sha256', $encoded, $key, true));
	}//end forge()

	/**
	 * Every outcome of openSignedLink().
	 *
	 * @return void
	 */
	public function testOpenSignedLink(): void {
		$controller = $this->controller();
		$valid = $controller->signLink('bk-1', 'cancel', 'ct-1');

		$this->assertSame('invalid', $controller->openSignedLink('no-dot'));
		$this->assertSame('invalid', $controller->openSignedLink('a.b.c'));
		$this->assertSame('invalid', $controller->openSignedLink(explode('.', $valid)[0] . '.forged'));
		$this->assertSame('invalid', $controller->openSignedLink($this->forge('not json')));
		$this->assertSame('invalid', $controller->openSignedLink($this->forge('{"bookingId":"bk-1"}')));
		$this->assertSame('expired', $controller->openSignedLink($this->forge('{"expiresAt":1699999999}')));
		$this->assertSame(['expiresAt' => 1700000000], $controller->openSignedLink($this->forge('{"expiresAt":1700000000}')));
		$this->assertSame(
			['bookingId' => 'bk-1', 'action' => 'cancel', 'customerId' => 'ct-1', 'issuedAt' => 1700000000, 'expiresAt' => 1702592000],
			$controller->openSignedLink($valid)
		);
	}//end testOpenSignedLink()

	/**
	 * Every refused and allowed outcome of reschedule().
	 *
	 * @return void
	 */
	public function testReschedule(): void {
		$gone = [410, ['error' => 'Link expired or invalid']];
		$good = $this->controller()->signLink('bk-1', 'reschedule');
		$cases = [
			[['newStartAt' => 'x'], [400, ['error' => 'token and newStartAt are required']]],
			[['token' => $good, 'newStartAt' => '  '], [400, ['error' => 'token and newStartAt are required']]],
			[['token' => 'bad', 'newStartAt' => 'x'], $gone],
			[['token' => $this->controller()->signLink('bk-1', 'cancel'), 'newStartAt' => 'x'], $gone],
			[['token' => $this->controller()->signLink('', 'reschedule'), 'newStartAt' => 'x'], $gone],
			[['token' => $this->forge('{"expiresAt":1800000000,"action":"reschedule"}'), 'newStartAt' => 'x'], $gone],
		];
		foreach ($cases as $i => [$params, $expected]) {
			$controller = $this->controller($params);
			$this->bookings->expects($this->never())->method('rescheduleBooking');
			$this->assertSame($expected, self::answer($controller->reschedule()), 'case ' . $i);
		}

		$outcomes = [
			[new InvalidArgumentException('no'), [400, ['error' => 'Invalid reschedule request']]],
			[new RuntimeException('down'), [503, ['error' => 'Reschedule failed']]],
			['bk-2', [200, ['bookingId' => 'bk-2', 'previousBookingId' => 'bk-1']]],
		];
		foreach ($outcomes as [$result, $expected]) {
			$controller = $this->controller(['token' => ' ' . $good . ' ', 'newStartAt' => ' 2026-06-11T10:00:00+00:00 ']);
			$mock = $this->bookings->expects($this->once())->method('rescheduleBooking')->with('bk-1', '2026-06-11T10:00:00+00:00');
			if ($result instanceof \Throwable) {
				$mock->willThrowException($result);
			} else {
				$mock->willReturn($result);
			}

			$this->assertSame($expected, self::answer($controller->reschedule()));
		}

		$this->assertSame(['error: Pipelinq portal: reschedule failed'], $this->logged);
	}//end testReschedule()

	/**
	 * Every refused and allowed outcome of cancel().
	 *
	 * @return void
	 */
	public function testCancel(): void {
		$gone = [410, ['error' => 'Link expired or invalid']];
		$good = $this->controller()->signLink('bk-1', 'cancel', 'ct-1');
		$cases = [
			[['reason' => 'r'], [400, ['error' => 'token is required']]],
			[['token' => 'bad'], $gone],
			[['token' => $this->controller()->signLink('bk-1', 'reschedule')], $gone],
			[['token' => $this->controller()->signLink('', 'cancel')], $gone],
		];
		foreach ($cases as $i => [$params, $expected]) {
			$controller = $this->controller($params);
			$this->bookings->expects($this->never())->method('cancelBooking');
			$this->assertSame($expected, self::answer($controller->cancel()), 'case ' . $i);
		}

		$long = str_repeat('x', 1200);
		$outcomes = [
			[new InvalidArgumentException('no'), [400, ['error' => 'Invalid cancel request']]],
			[new RuntimeException('down'), [503, ['error' => 'Cancellation failed']]],
			[null, [200, ['bookingId' => 'bk-1', 'cancelled' => true]]],
		];
		foreach ($outcomes as [$error, $expected]) {
			$controller = $this->controller(['token' => $good, 'reason' => $long]);
			$mock = $this->bookings->expects($this->once())->method('cancelBooking')->with('bk-1', str_repeat('x', 1000), 'ct-1');
			if ($error !== null) {
				$mock->willThrowException($error);
			}

			$this->assertSame($expected, self::answer($controller->cancel()));
		}

		$controller = $this->controller(['token' => $this->controller()->signLink('bk-3', 'cancel')]);
		$this->bookings->expects($this->once())->method('cancelBooking')->with('bk-3', '', '');
		$this->assertSame([200, ['bookingId' => 'bk-3', 'cancelled' => true]], self::answer($controller->cancel()));
		$this->assertSame(['error: Pipelinq portal: cancel failed'], $this->logged);
	}//end testCancel()

	/**
	 * The book() body refusals, in order.
	 *
	 * @return void
	 */
	public function testBookValidation(): void {
		$body = ['customerName' => 'A', 'email' => 'a@b.test', 'phone' => '1', 'serviceId' => 's', 'startAt' => '2026-06-11T10:00:00+00:00'];
		$cases = [
			[['customerName' => ''], [400, ['error' => 'Missing required field']]],
			[['phone' => ' '], [400, ['error' => 'Missing required field']]],
			[['startAt' => '', 'email' => 'bad'], [400, ['error' => 'Missing required field']]],
			[['email' => 'bad', 'startAt' => 'never'], [400, ['error' => 'Invalid email']]],
			[['startAt' => 'never'], [400, ['error' => 'Invalid startAt']]],
		];
		foreach ($cases as $i => [$override, $expected]) {
			$this->assertSame($expected, self::answer($this->controller(array_merge($body, $override))->book()), 'case ' . $i);
		}
	}//end testBookValidation()

	/**
	 * The services projection, its filtering and its failures.
	 *
	 * @return void
	 */
	public function testServices(): void {
		$rows = [
			['@self' => ['id' => 's-1'], 'name' => 'Cut', 'bookableOnline' => true, 'durationMinutes' => '30', 'price' => '27.5', 'requiresDeposit' => 1, 'internal' => 'x'],
			['@self' => ['id' => 's-2'], 'name' => 'Hidden', 'bookableOnline' => 'yes'],
			['@self' => ['id' => 's-3'], 'name' => 'Off'],
			'junk',
		];
		$cut = [
			'id' => 's-1', 'name' => 'Cut', 'description' => '', 'durationMinutes' => 30, 'price' => 27.5,
			'currency' => 'EUR', 'requiresDeposit' => true, 'depositAmount' => 0.0,
		];

		$this->assertSame([200, ['services' => [$cut]]], self::answer($this->controller([], ['results' => $rows])->services()));
		$this->assertSame([200, ['services' => [$cut]]], self::answer($this->controller([], $rows)->services()));
		$this->assertSame([200, ['services' => []]], self::answer($this->controller([], ['results' => 'none'])->services()));
		$this->assertSame([200, ['services' => []]], self::answer($this->controller([], new RuntimeException('down'))->services()));
		$this->config['service_schema'] = '';
		$this->assertSame([503, ['error' => 'Service unavailable']], self::answer($this->controller([], $rows)->services()));
		$this->assertSame(
			['warning: Pipelinq portal: services findAll failed', 'warning: Pipelinq portal: services lookup unavailable'],
			array_values(array_filter($this->logged, static fn (string $line): bool => str_contains($line, 'services')))
		);
	}//end testServices()
}//end class
