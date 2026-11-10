<?php

/**
 * Unit tests for BerichtenboxAdminController.
 *
 * Covers the admin retry of a failed Berichtenbox message: the message is
 * read with the configured register and schema, reset to queued and saved
 * back; an unreadable message answers 404.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Controller\BerichtenboxAdminController;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BerichtenboxAdminController::retry().
 */
class BerichtenboxAdminControllerTest extends TestCase {
	/**
	 * Build the controller over the given object service.
	 *
	 * @param ObjectServiceInterface $objects The object service double.
	 *
	 * @return BerichtenboxAdminController The controller.
	 */
	private function controller(ObjectServiceInterface $objects): BerichtenboxAdminController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'pipelinq', 'berichtenboxMessage_schema' => 'bbMessage'][$key] ?? $default)
		);

		return new BerichtenboxAdminController(
			request: $this->createMock(IRequest::class),
			appConfig: $appConfig,
			objectService: $objects,
		);
	}//end controller()

	/**
	 * A failed message is read from the configured register and schema and saved back as queued.
	 *
	 * @return void
	 */
	public function testRetryRequeuesTheMessage(): void {
		$message = new ObjectEntity();
		$message->setUuid('m-1');
		$message->setObject(['uuid' => 'm-1', 'deliveryStatus' => 'failed', 'retryCount' => 5, 'nextRetryAt' => '2026-11-11']);

		$saved = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())
			->method('find')
			->with(
				'm-1',
				$this->callback(static fn (?ObjectFindOptions $options): bool => $options?->register === 'pipelinq' && $options?->schema === 'bbMessage'),
			)
			->willReturn($message);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $message): ObjectEntity {
				$saved = $object;
				return $message;
			}
		);

		$response = $this->controller(objects: $objects)->retry(id: 'm-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ok' => true, 'id' => 'm-1', 'status' => 'queued'], $response->getData());
		$this->assertSame('queued', $saved['deliveryStatus']);
		$this->assertSame(0, $saved['retryCount']);
		$this->assertSame('', $saved['nextRetryAt']);
	}//end testRetryRequeuesTheMessage()

	/**
	 * A message the object service cannot find answers 404 and is not saved.
	 *
	 * @return void
	 */
	public function testRetryOfAnUnknownMessageIsNotFound(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willThrowException(new RuntimeException('gone'));
		$objects->expects($this->never())->method('saveObject');

		$response = $this->controller(objects: $objects)->retry(id: 'm-404');

		$this->assertSame(404, $response->getStatus());
	}//end testRetryOfAnUnknownMessageIsNotFound()
}//end class
