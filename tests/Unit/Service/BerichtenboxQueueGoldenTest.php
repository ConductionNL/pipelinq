<?php

/**
 * Golden test for BerichtenboxService::queueOutboundMessage().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCA\Pipelinq\Service\TemplateRenderer;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Every row queueOutboundMessage() saves, every collaborator call it makes
 * (by parameter name, in order) and every result or error, compared with
 * BerichtenboxQueueGolden.json. Set BERICHTENBOX_QUEUE_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\BerichtenboxService
 * @uses   \OCA\Pipelinq\Service\TemplateRenderer
 * @uses   \OCA\Pipelinq\Service\DutchHolidayCalendar
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */
class BerichtenboxQueueGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/BerichtenboxQueueGolden.json';

	/**
	 * Collaborator calls in the current scenario, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('BERICHTENBOX_QUEUE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: the arguments, the config, the stored templates and failures.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$args = ['caseId' => 'Z-1', 'interactionId' => 'cm-1', 'status' => 'afgehandeld', 'bsn' => '123456782'];
		$template = ['id' => 'tpl-1', 'uuid' => 'tpl-uuid', 'subject' => 'Zaak {{caseId}}: {{status}}', 'body' => 'Beste, {{gemeente}} meldt {{status}} voor {{caseId}} ({{deadline}}) {{messageId}}'];
		return [
			'fallback template'                 => ['args' => [...$args, 'extraVariables' => ['caseType' => 'paspoort']]],
			'template from the registry'        => ['args' => [...$args, 'extraVariables' => ['caseType' => 'paspoort', 'language' => 'en']], 'templates' => [$template]],
			'registry template with uuid only'  => ['args' => $args, 'templates' => [['uuid' => 'tpl-uuid', 'subject' => 'S', 'body' => 'B {{caseId}}']]],
			'registry template without ids'     => ['args' => $args, 'templates' => [['subject' => 'S', 'body' => 'B']]],
			'override skips the registry'       => ['args' => [...$args, 'templateOverride' => ['subject' => 'O {{status}}', 'body' => 'Override {{caseId}}']], 'templates' => [$template]],
			'override with an id and a uuid'    => ['args' => [...$args, 'templateOverride' => ['id' => 'o-id', 'uuid' => 'o-uuid', 'subject' => 'S', 'body' => 'B']]],
			'no interaction'                    => ['args' => [...$args, 'interactionId' => null]],
			'extra variables override defaults' => ['args' => [...$args, 'extraVariables' => ['gemeente' => 'Utrecht', 'messageId' => 'm-given', 'deadline' => '2026-12-01', 'extra' => 'x']], 'templates' => [$template]],
			'attachments'                       => ['args' => [...$args, 'attachments' => [['filename' => 'a.pdf', 'mime' => 'application/pdf', 'sizeBytes' => 10, 'openregisterFileRef' => 'f-1']]]],
			'no tenant configured'              => ['args' => $args, 'config' => ['tenant_id' => '']],
			'no display name configured'        => ['args' => $args, 'config' => ['tenant_display_name' => null], 'templates' => [$template]],
			'no template schema'                => ['args' => $args, 'config' => ['berichtenboxTemplate_schema' => ''], 'templates' => [$template]],
			'no register'                       => ['args' => $args, 'config' => ['register' => ''], 'templates' => [$template]],
			'registry lookup throws'            => ['args' => $args, 'findThrows' => true],
			'save throws'                       => ['args' => $args, 'saveThrows' => true],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh service.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$config = [
			'register' => 'reg-1',
			'berichtenboxMessage_schema' => 'sch-msg',
			'berichtenboxTemplate_schema' => 'sch-tpl',
			'tenant_id' => 'tenant-a',
			'tenant_display_name' => 'Gemeente Amsterdam',
			...($scenario['config'] ?? []),
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$encryption = $this->createMock(EncryptionService::class);
		$encryption->method('hashBsn')->willReturnCallback($this->recorder(target: [EncryptionService::class, 'hashBsn'], answer: static fn (array $a): string => 'hash(' . $a['plaintext'] . '|' . $a['tenantId'] . ')'));
		$encryption->method('encrypt')->willReturnCallback($this->recorder(target: [EncryptionService::class, 'encrypt'], answer: static fn (array $a): string => 'enc(' . $a['plaintext'] . '|' . $a['tenantId'] . ')'));

		$uuids = 0;
		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('newUuidV4')->willReturnCallback($this->recorder(target: [LogiusConnector::class, 'newUuidV4'], answer: static function () use (&$uuids): string {
			return 'uuid-' . (++$uuids);
		}));

		$audit = $this->createMock(DeliveryAuditLogger::class);
		$audit->method('hashPayload')->willReturnCallback($this->recorder(target: [DeliveryAuditLogger::class, 'hashPayload'], answer: static fn (array $a): string => 'sha(' . $a['body'] . ')'));
		$audit->method('calculateRetentionUntil')->willReturnCallback($this->recorder(target: [DeliveryAuditLogger::class, 'calculateRetentionUntil'], answer: static fn (array $a): DateTimeImmutable => new DateTimeImmutable(($a['caseType'] === '' ? '2036' : '2040') . '-01-01T00:00:00+00:00')));
		$audit->method('logQueued')->willReturnCallback($this->recorder(target: [DeliveryAuditLogger::class, 'logQueued'], answer: static fn (array $a): mixed => null));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback($this->recorder(target: [ObjectServiceInterface::class, 'findAll'], answer: static function (array $a) use ($scenario): array {
			if (($scenario['findThrows'] ?? false) === true) {
				throw new RuntimeException('find failed');
			}

			return array_map(static fn (array $row): ObjectEntityInterface => self::entity(payload: $row), ($scenario['templates'] ?? []));
		}));
		$objects->method('saveObject')->willReturnCallback($this->recorder(target: [ObjectServiceInterface::class, 'saveObject'], answer: static function (array $a) use ($scenario): ObjectEntityInterface {
			if (($scenario['saveThrows'] ?? false) === true) {
				throw new RuntimeException('save failed');
			}

			return self::entity(payload: [...$a['object'], 'stored' => true]);
		}));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$service = new BerichtenboxService(
			container: $container,
			appConfig: $appConfig,
			encryption: $encryption,
			templateRenderer: new TemplateRenderer($this->createMock(LoggerInterface::class)),
			mailboxResolver: $this->createMock(MailboxResolver::class),
			logius: $logius,
			emailFallback: $this->createMock(EmailFallbackSender::class),
			auditLogger: $audit,
			holidayCalendar: new DutchHolidayCalendar(),
			ticketService: $this->createMock(TicketService::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
		);

		try {
			$outcome = ['result' => $service->queueOutboundMessage(...$scenario['args'])];
		} catch (Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return [...$outcome, 'calls' => $this->calls];
	}//end runScenario()

	/**
	 * A callback that records its call by parameter name, without arguments left at their default, and answers it.
	 *
	 * @param array{0: class-string, 1: string} $target The class and method, read by reflection on the loaded class.
	 * @param callable                          $answer Receives the named arguments and gives the return value.
	 *
	 * @return callable
	 */
	private function recorder(array $target, callable $answer): callable {
		$params = (new ReflectionMethod($target[0], $target[1]))->getParameters();
		return function (...$args) use ($target, $params, $answer): mixed {
			$named = [];
			foreach ($params as $i => $param) {
				if (array_key_exists($i, $args) === false) {
					break;
				}

				if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
					continue;
				}

				$named[$param->getName()] = $args[$i];
			}

			$this->calls[] = ['call' => $target[1], 'args' => array_map(static fn (mixed $v): mixed => ($v instanceof DateTimeImmutable ? $v->format(DATE_ATOM) : $v), $named)];
			return $answer($named);
		};
	}//end recorder()

	/**
	 * An OpenRegister object holding the payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(array $payload): ObjectEntityInterface {
		$entity = new ObjectEntity();
		if (is_string($payload['uuid'] ?? null) === true) {
			$entity->setUuid($payload['uuid']);
		}

		$entity->setObject($payload);
		return $entity;
	}//end entity()
}//end class
