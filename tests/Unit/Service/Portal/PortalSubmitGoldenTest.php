<?php

/**
 * Golden test for PortalRequestService::submit: every refusal and the full success path.
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

use OCA\Pipelinq\Event\PortalRequestSubmittedEvent;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Submits portal requests through every gate — missing fields, the rate limit,
 * an oversize attachment, an unexposed category — and through the success path
 * (record saved, SLA event, audit, receipt with each reference fallback, a failing
 * dispatch), recording every collaborator call. Time is frozen.
 */
class PortalSubmitGoldenTest extends TestCase {
	/**
	 * Frozen "now" (2026-03-01T12:00:00+00:00).
	 *
	 * @var int
	 */
	private const NOW = 1772366400;

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Every case and its calls plus outcome.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$account = ['id' => 'acc-1', 'linkedContactId' => ['', 'c-1'], 'linkedOrganisationId' => 'o-1'];
		$recent = ['occurredAt' => '2026-03-01T11:30:00+00:00'];
		$old = ['occurredAt' => '2026-03-01T10:59:00+00:00'];
		return [
			'missing subject' => ['args' => [$account, 't-1', '  ', 'Body', [], 'cat-1']],
			'missing body' => ['args' => [$account, 't-1', 'Subject', "\n", [], 'cat-1']],
			'rate limited' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-1'], 'existing' => [$recent, $recent, $recent, $recent, $recent]],
			'just under the rate limit' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-1'], 'existing' => [$recent, $recent, $recent, $recent, $old, ['occurredAt' => 'junk']]],
			'oversize attachment' => ['args' => [$account, 't-1', 'Subject', 'Body', [['id' => 'f-1', 'size' => 100], ['id' => 'f-2', 'size' => 26214401]], 'cat-1']],
			'category not exposed' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-hidden']],
			'category missing' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-gone']],
			'no category schema' => ['args' => [$account, 't-1', ' Subject ', ' Body ', [['id' => 7, 'size' => 1], ['size' => 2]], 'anything'], 'categories' => false],
			'saved with a case reference' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-1'], 'saved' => ['caseReference' => ' VZ-2026-1 ', 'reference' => 'R', 'occurredAt' => '2026-03-01T12:00:01+00:00']],
			'saved with a reference' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-1'], 'saved' => ['reference' => 'R-9']],
			'saved without a reference' => ['args' => [['id' => 'acc-2'], 't-2', 'Subject', 'Body', [], 'cat-1'], 'saved' => []],
			'dispatch fails' => ['args' => [$account, 't-1', 'Subject', 'Body', [], 'cat-1'], 'dispatchFails' => true],
		];
	}//end cases()

	/**
	 * Run every case and compare with the inline expectations recorded in the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->trace = [];
			$service = $this->service(case: $case);
			try {
				$outcome = ['result' => $service->submit(...$case['args'])];
			} catch (PortalException $e) {
				$outcome = ['refused' => [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]];
			}

			$actual[$name] = ['calls' => $this->trace] + $outcome;
		}

		$golden = __DIR__ . '/PortalSubmitGolden.json';
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('PORTAL_SUBMIT_GOLDEN_RECORD') !== false) {
			file_put_contents($golden, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents($golden), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * A service on recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return PortalRequestService
	 */
	private function service(array $case): PortalRequestService {
		$reader = $this->createMock(MainRegisterReader::class);
		$reader->method('findAll')->willReturnCallback(
			function (string $schemaKey, array $filters = []) use ($case): array {
				$this->trace[] = ['findAll', $schemaKey, $filters];
				return ($case['existing'] ?? []);
			}
		);
		$reader->method('hasSchema')->willReturnCallback(
			function (string $schemaKey) use ($case): bool {
				$this->trace[] = ['hasSchema', $schemaKey];
				return ($case['categories'] ?? true);
			}
		);
		$reader->method('find')->willReturnCallback(
			function (string $schemaKey, string $id): ?array {
				$this->trace[] = ['find', $schemaKey, $id];
				return match ($id) {
					'cat-1' => ['exposeToCustomer' => true],
					'cat-hidden' => ['exposeToCustomer' => 'yes'],
					default => null,
				};
			}
		);
		$reader->method('save')->willReturnCallback(
			function (string $schemaKey, array $data, ?string $id = null) use ($case): array {
				$this->trace[] = ['save', $schemaKey, $data, $id];
				return ['id' => 'req-1'] + ($case['saved'] ?? $data);
			}
		);

		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			function (?string $accountId, string $tenantId, string $eventType, string $outcome, array $details = []): void {
				$this->trace[] = ['audit', $accountId, $tenantId, $eventType, $outcome, $details];
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (PortalRequestSubmittedEvent $event) use ($case): void {
				$this->trace[] = ['dispatchTyped', $event->getRequestId(), $event->getTenantId()];
				if (($case['dispatchFails'] ?? false) === true) {
					throw new \RuntimeException('no listener');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->trace[] = ['warning', $message, $context];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('@' . self::NOW));

		return new PortalRequestService($reader, $this->createMock(PortalScopeResolver::class), $audit, $dispatcher, $time, $logger);
	}//end service()
}//end class
