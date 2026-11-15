<?php

/**
 * Golden test for the subscription flows: subscribe, confirm, soft opt-in import, contact confirmation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\SubscriptionService;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs every outcome of the four flows that put a person on a list, and compares
 * every call made — the store, the tokens, the lists, the consent record, the
 * mail and the log — plus the answer, with a recorded golden file. Timestamps
 * are replaced by NOW.
 */
class SubscriptionFlowGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SubscriptionFlowGolden.json';

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$double = ['id' => 'list-1', 'name' => 'Nieuws', 'status' => 'active', 'optInMode' => 'double', 'senderEmail' => 'news@ex.nl', 'senderName' => 'Nieuws', 'replyTo' => 'reply@ex.nl', 'footerAddress' => 'Straat 1'];
		$soft = ['optInMode' => 'soft'] + $double;
		$pending = ['id' => 'sub-1', 'listId' => 'list-1', 'email' => 'jan@ex.nl', 'state' => 'pending', 'confirmTokenHash' => 'digest:nonce-1', 'createdAt' => '2026-01-01T00:00:00Z'];
		$cases = [
			'subscribe, list not public' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'public' => false],
			'subscribe, invalid address' => ['call' => fn ($s) => $s->subscribe('list-1', 'not-an-address'), 'list' => $double],
			'subscribe, honeypot' => ['call' => fn ($s) => $s->subscribe('list-1', ' Jan@Ex.nl ', 'I am a bot'), 'list' => $double],
			'subscribe, already confirmed' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'list' => $double, 'rows' => [['id' => 'sub-1', 'email' => 'JAN@ex.nl', 'state' => 'confirmed']]],
			'subscribe, new' => ['call' => fn ($s) => $s->subscribe('list-1', ' Jan@Ex.nl ', '', 'c-1'), 'list' => $double],
			'subscribe, pending again' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl', ' ', ''), 'list' => ['senderEmail' => 'bad', 'replyTo' => ''] + $double, 'rows' => [$pending]],
			'subscribe, unsubscribed again' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'list' => $double, 'rows' => [['state' => 'unsubscribed', 'contactId' => 'c-9'] + $pending]],
			'subscribe, save fails' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'list' => $double, 'saveFails' => true],
			'subscribe, saved without id' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'list' => $double, 'savedWithoutId' => true],
			'subscribe, mail fails' => ['call' => fn ($s) => $s->subscribe('list-1', 'jan@ex.nl'), 'list' => $double, 'mailFails' => true],
			'confirm, bad token' => ['call' => fn ($s) => $s->confirm('bad', '1.2.3.4')],
			'confirm, unknown subscription' => ['call' => fn ($s) => $s->confirm('ok:sub-x:nonce-1', '1.2.3.4')],
			'confirm, no nonce' => ['call' => fn ($s) => $s->confirm('ok:sub-1:', '1.2.3.4'), 'stored' => $pending],
			'confirm, spent link' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', '1.2.3.4'), 'stored' => ['confirmTokenHash' => ''] + $pending],
			'confirm, wrong nonce' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-2', '1.2.3.4'), 'stored' => $pending],
			'confirm, already confirmed' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', '1.2.3.4'), 'stored' => ['state' => 'confirmed'] + $pending],
			'confirm, save fails' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', '1.2.3.4'), 'stored' => $pending, 'saveFails' => true],
			'confirm, list gone' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', ''), 'stored' => $pending],
			'confirm, confirmed' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', '1.2.3.4'), 'stored' => ['contactId' => 'c-1'] + $pending, 'list' => $double],
			'confirm, no createdAt' => ['call' => fn ($s) => $s->confirm('ok:sub-1:nonce-1', '1.2.3.4'), 'stored' => array_diff_key($pending, ['createdAt' => 1]), 'list' => $double],
			'import, list gone' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'jan@ex.nl', ['objectionOffered' => true])],
			'import, double opt-in list' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'jan@ex.nl', ['objectionOffered' => true]), 'list' => $double],
			'import, invalid address' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'nope', ['objectionOffered' => true]), 'list' => $soft],
			'import, no objection offered' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'jan@ex.nl', ['objectionOffered' => 0]), 'list' => $soft],
			'import, new' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', ' JAN@ex.nl', ['objectionOffered' => true, 'wording' => 'w', 'recordedAt' => 'given']), 'list' => $soft],
			'import, existing' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'jan@ex.nl', ['objectionOffered' => true]), 'list' => $soft, 'rows' => [$pending]],
			'import, save fails' => ['call' => fn ($s) => $s->importSoftOptIn('list-1', 'c-1', 'jan@ex.nl', ['objectionOffered' => true]), 'list' => $soft, 'saveFails' => true],
			'contact, list gone' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', 'jan@ex.nl')],
			'contact, list archived' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', 'jan@ex.nl'), 'list' => ['status' => 'archived'] + $double],
			'contact, no row and no address' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', ''), 'list' => $double],
			'contact, already confirmed' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', 'jan@ex.nl'), 'list' => $double, 'rows' => [['state' => 'confirmed'] + $pending]],
			'contact, new' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', 'jan@ex.nl', 'portal'), 'list' => array_diff_key($double, ['status' => 1])],
			'contact, existing pending' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', ''), 'list' => $double, 'rows' => [$pending, ['id' => 'sub-2'] + $pending]],
			'contact, save fails' => ['call' => fn ($s) => $s->confirmForContact('list-1', 'c-1', 'jan@ex.nl'), 'list' => $double, 'saveFails' => true],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$this->trace = [];
			$result = ($case['call'])($this->service(case: $case));
			$actual[$name] = $this->normalised(value: ['result' => $result, 'calls' => $this->trace]);
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('SUBSCRIPTION_FLOW_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * Replace every generated UTC timestamp with NOW.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value with timestamps replaced.
	 */
	private function normalised(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $entry): mixed => $this->normalised(value: $entry), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1 && str_starts_with($value, '2026-01-01') === false) {
			return 'NOW';
		}

		return $value;
	}//end normalised()

	/**
	 * A service on recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return SubscriptionService
	 */
	private function service(array $case): SubscriptionService {
		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturn('subscription');
		$store->method('findAll')->willReturnCallback(
			function (string $schemaSlug, array $filters) use ($case): array {
				$this->trace[] = ['findAll', $schemaSlug, $filters];
				return ($case['rows'] ?? []);
			}
		);
		$store->method('find')->willReturnCallback(
			function (string $schemaSlug, string $id) use ($case): ?array {
				$this->trace[] = ['find', $schemaSlug, $id];
				return ($id === 'sub-1' ? ($case['stored'] ?? null) : null);
			}
		);
		$store->method('save')->willReturnCallback(
			function (string $schemaSlug, array $payload, ?string $id = null) use ($case): ?array {
				$this->trace[] = ['save', $schemaSlug, $payload, $id];
				if (($case['saveFails'] ?? false) === true) {
					return null;
				}

				if (($case['savedWithoutId'] ?? false) === true) {
					return $payload;
				}

				return ['id' => ($id === null || $id === '' ? 'sub-new' : $id)] + $payload;
			}
		);
		$store->method('idOf')->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));

		$tokens = $this->createMock(ListTokenService::class);
		$tokens->method('mintNonce')->willReturn('nonce-new');
		$tokens->method('digest')->willReturnCallback(static fn (string $nonce): string => 'digest:' . $nonce);
		$tokens->method('hashAddress')->willReturnCallback(static fn (string $address): string => 'hash:' . $address);
		$tokens->method('signConfirmToken')->willReturnCallback(static fn (string $subscriptionId, string $nonce): string => 'ok:' . $subscriptionId . ':' . $nonce);
		$tokens->method('confirmUrl')->willReturnCallback(static fn (string $token): string => 'https://ex.nl/confirm/' . $token);
		$tokens->method('verify')->willReturnCallback(
			function (string $token, string $purpose): ?array {
				$this->trace[] = ['verify', $token, $purpose];
				$parts = explode(':', $token);
				if ($parts[0] !== 'ok') {
					return null;
				}

				return ['s' => $parts[1], 'n' => $parts[2]];
			}
		);

		$lists = $this->createMock(MailingListService::class);
		$lists->method('getMailingListById')->willReturnCallback(
			function (string $listId) use ($case): ?array {
				$this->trace[] = ['getMailingListById', $listId];
				return ($case['list'] ?? null);
			}
		);
		$lists->method('acceptsPublicSignup')->willReturn(($case['public'] ?? true));
		$lists->method('publicProjection')->willReturnCallback(static fn (array $list): array => ['name' => $list['name']]);

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('recordListConsent')->willReturnCallback(
			function (mixed ...$args): void {
				$this->trace[] = ['recordListConsent', $args];
			}
		);

		$message = $this->createMock(IMessage::class);
		foreach (['setTo', 'setSubject', 'setPlainBody', 'setFrom', 'setReplyTo'] as $setter) {
			$message->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $message): IMessage {
					$this->trace[] = [$setter, $args];
					return $message;
				}
			);
		}

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $email): bool => str_contains($email, '@') === true);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function () use ($case): array {
				$this->trace[] = ['send'];
				if (($case['mailFails'] ?? false) === true) {
					throw new \RuntimeException('smtp down');
				}

				return [];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->trace[] = [$level, $message, $context];
				}
			);
		}

		return new SubscriptionService($store, $tokens, $lists, $compliance, $mailer, $l10n, $logger);
	}//end service()
}//end class
