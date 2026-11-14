<?php

/**
 * Stand-ins for the Mail app classes MailAccountTransport resolves, loaded
 * only inside a separate test process so the "Mail app absent" tests keep
 * seeing no Mail app.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport\Fixtures
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Mail\Service;

/**
 * The Mail app's account service: records each lookup.
 */
class AccountService {
	/** @var array<int, mixed> */
	public static array $calls = [];

	/** @var \Throwable|null */
	public static ?\Throwable $failure = null;

	/**
	 * Find one account of one user.
	 *
	 * @param string $userId    The owner.
	 * @param int    $accountId The account id.
	 *
	 * @return object The account.
	 */
	public function find(string $userId, int $accountId): object {
		self::$calls[] = ['find', $userId, $accountId];
		if (self::$failure !== null) {
			throw self::$failure;
		}

		return (object)['id' => $accountId, 'userId' => $userId];
	}
}

/**
 * The Mail app's outbox: records each save and send.
 */
class OutboxService {
	/** @var array<int, mixed> */
	public static array $calls = [];

	/** @var \Throwable|null */
	public static ?\Throwable $failure = null;

	/**
	 * Save a message to the outbox.
	 *
	 * @param object       $account The account.
	 * @param object       $message The message.
	 * @param array<mixed> $to      Recipients.
	 * @param array<mixed> $cc      Cc.
	 * @param array<mixed> $bcc     Bcc.
	 *
	 * @return object The saved message.
	 */
	public function saveMessage(object $account, object $message, array $to, array $cc, array $bcc): object {
		self::$calls[] = ['saveMessage', $account, $message->calls, $to, $cc, $bcc];
		return (object)['saved' => true];
	}

	/**
	 * Send a saved message.
	 *
	 * @param object $saved   The saved message.
	 * @param object $account The account.
	 *
	 * @return void
	 */
	public function sendMessage(object $saved, object $account): void {
		self::$calls[] = ['sendMessage', $saved, $account];
		if (self::$failure !== null) {
			throw self::$failure;
		}
	}
}

namespace OCA\Mail\Db;

/**
 * The Mail app's local message: records each setter.
 */
class LocalMessage {
	/** @var array<int, mixed> */
	public array $calls = [];

	/**
	 * Record a setter.
	 *
	 * @param string       $name The setter.
	 * @param array<mixed> $args Its arguments.
	 *
	 * @return void
	 */
	public function __call(string $name, array $args): void {
		$this->calls[] = [$name, ...$args];
	}
}
