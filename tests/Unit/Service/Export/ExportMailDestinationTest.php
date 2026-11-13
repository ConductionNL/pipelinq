<?php

/**
 * ExportDestinationService: the mail destination.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A mail destination needs recipients that exist, no connector source or
 * path, keeps its recipients, and is valid while every recipient exists.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportDestinationService
 * @covers \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Adapter\ExportSinkRegistry
 * @uses   \OCA\Pipelinq\Service\ConnectorSourceRegister
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ExportMailDestinationTest extends TestCase {

	/**
	 * Stored destinations, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The users that exist.
	 *
	 * @var array<int, string>
	 */
	private array $users = ['ann', 'bob'];

	/**
	 * The groups that exist.
	 *
	 * @var array<int, string>
	 */
	private array $groups = ['sales'];

	/**
	 * The service under test over an array-backed object service.
	 *
	 * @return ExportDestinationService
	 */
	private function service(): ExportDestinationService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => (isset($this->stored[$id]) === true ? self::entity((string)$id, $this->stored[$id]) : null)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$uuid = ($uuid ?? 'dest-1');
				$this->stored[$uuid] = $object;
				return self::entity($uuid, $object);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg', 'exportDestination_schema' => 'dest'][$key] ?? $default)
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->users, true));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(fn (string $gid): bool => in_array($gid, $this->groups, true));

		return new ExportDestinationService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			objectService: $objects,
			sinks: new ExportSinkRegistry([]),
			connectorRegister: FakeSlugResolver::connectorRegister(),
			logger: $this->createMock(LoggerInterface::class),
			userManager: $users,
			groupManager: $groups,
		);
	}//end service()

	/**
	 * An entity with the given payload.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A mail destination to a user and a group is stored with its recipients,
	 * without a connector source or path, and tests valid.
	 *
	 * @return void
	 */
	public function testAMailDestinationIsStoredAndValid(): void {
		$recipients = [['type' => 'user', 'id' => 'ann'], ['type' => 'group', 'id' => 'sales']];

		$destination = $this->service()->createDestination(
			data: ['name' => 'Weekly to sales', 'type' => 'mail', 'recipients' => $recipients, 'apiKey' => 'x']
		);

		$this->assertSame('mail', $destination['type']);
		$this->assertSame($recipients, $destination['recipients']);
		$this->assertSame('valid', $destination['validationStatus']);
		$this->assertArrayNotHasKey('apiKey', $this->stored['dest-1']);
		$this->assertTrue($this->service()->isValid(id: 'dest-1'));
	}//end testAMailDestinationIsStoredAndValid()

	/**
	 * A mail destination without recipients, with a malformed one or with
	 * one that does not exist is refused.
	 *
	 * @param mixed  $recipients The posted recipients.
	 * @param string $message    The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('badRecipients')]
	public function testBadRecipientsAreRefused(mixed $recipients, string $message): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage($message);

		$this->service()->createDestination(data: ['name' => 'Weekly', 'type' => 'mail', 'recipients' => $recipients]);
	}//end testBadRecipientsAreRefused()

	/**
	 * Recipient lists that are refused, with the message.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function badRecipients(): array {
		return [
			'none' => [[], 'A mailed report needs at least one recipient.'],
			'not a list' => ['ann', 'A mailed report needs at least one recipient.'],
			'unknown user' => [[['type' => 'user', 'id' => 'zoe']], "Recipient 'user:zoe' does not exist."],
			'unknown group' => [[['type' => 'user', 'id' => 'ann'], ['type' => 'group', 'id' => 'hr']], "Recipient 'group:hr' does not exist."],
			'unknown kind' => [[['type' => 'email', 'id' => 'a@b.c']], "Recipient 'email:a@b.c' does not exist."],
			'no id' => [[['type' => 'user']], "Recipient 'user:' does not exist."],
			'not an object' => [['ann'], "Recipient ':' does not exist."],
		];
	}//end badRecipients()

	/**
	 * A mail destination whose recipient was removed since tests invalid.
	 *
	 * @return void
	 */
	public function testARemovedRecipientMakesTheDestinationInvalid(): void {
		$service = $this->service();
		$service->createDestination(data: ['name' => 'Weekly', 'type' => 'mail', 'recipients' => [['type' => 'user', 'id' => 'bob']]]);
		$this->users = ['ann'];

		$this->assertFalse($service->testConnection(id: 'dest-1'));
		$this->assertSame('invalid', $this->stored['dest-1']['validationStatus']);
	}//end testARemovedRecipientMakesTheDestinationInvalid()

	/**
	 * Other types still need a connector source.
	 *
	 * @return void
	 */
	public function testOtherTypesStillNeedAConnectorSource(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('An OpenConnector source is required for credentials.');

		$this->service()->createDestination(data: ['name' => 'Lake', 'type' => 's3', 'recipients' => [['type' => 'user', 'id' => 'ann']]]);
	}//end testOtherTypesStillNeedAConnectorSource()
}//end class
