<?php

/**
 * Followers of a ticket or lead are told about its updates.
 *
 * Merges the real monolith and the real register.d fragments through
 * ConfigFileLoaderService and reads the notification rules of ticket and
 * lead as the install imports them.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
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

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The four update rules address the watchers; the two create rules do not.
 *
 * @spec openspec/changes/notify-the-followers-of-tickets-and-leads/specs/notifications-activity/spec.md#requirement-followers-of-a-ticket-or-lead-receive-its-update-notifications-req-nftl-001
 * @spec openspec/changes/notify-the-followers-of-tickets-and-leads/specs/notifications-activity/spec.md#requirement-adding-followers-keeps-every-existing-recipient-req-nftl-002
 * @spec openspec/changes/notify-the-followers-of-tickets-and-leads/specs/notifications-activity/spec.md#requirement-the-watchers-block-passes-openregisters-schema-validation-req-nftl-003
 */
class WatcherRecipientsTest extends TestCase {
	/**
	 * The merged register configuration.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function merged(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		return (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
	}//end merged()

	/**
	 * The rules that fire after an object exists keep their recipients and gain one watchers block.
	 *
	 * @return void
	 */
	public function testUpdateRulesAddressTheWatchers(): void {
		$schemas = $this->merged()['components']['schemas'];
		$expected = [
			['ticket', 'ticketUpdated', [['kind' => 'field', 'field' => 'assignee']]],
			['lead', 'leadUpdated', [['kind' => 'field', 'field' => 'assignee']]],
			['lead', 'leadWon', [['kind' => 'groups', 'groups' => ['sales']], ['kind' => 'field', 'field' => 'assignee']]],
			['lead', 'leadLost', [['kind' => 'field', 'field' => 'assignee']]],
		];

		foreach ($expected as [$schema, $rule, $before]) {
			$recipients = $schemas[$schema]['x-openregister-notifications'][$rule]['recipients'];
			$this->assertSame(array_merge($before, [['watchers' => true]]), $recipients, "{$schema}.{$rule}");
		}
	}//end testUpdateRulesAddressTheWatchers()

	/**
	 * Nobody can follow an object before it exists, so the create rules stay as they are.
	 *
	 * @return void
	 */
	public function testCreateRulesAddressNoWatchers(): void {
		$schemas = $this->merged()['components']['schemas'];
		foreach ([['ticket', 'newTicket'], ['lead', 'newLead']] as [$schema, $rule]) {
			$recipients = $schemas[$schema]['x-openregister-notifications'][$rule]['recipients'];
			$this->assertNotContains(['watchers' => true], $recipients, "{$schema}.{$rule}");
			$this->assertContains(['kind' => 'field', 'field' => 'assignee'], $recipients, "{$schema}.{$rule}");
		}
	}//end testCreateRulesAddressNoWatchers()

	/**
	 * OpenRegister's own validator accepts the ticket and lead rules.
	 *
	 * Needs an OpenRegister checkout: OPENREGISTER_PATH, or `openregister`
	 * next to this app. Without one the test is skipped and the live check
	 * (task 3.1) carries the requirement.
	 *
	 * @return void
	 */
	public function testOpenRegisterAcceptsTheRules(): void {
		$validator = $this->openRegisterValidator();
		if ($validator === null) {
			$this->markTestSkipped('No OpenRegister checkout with NotificationAnnotationValidator found (set OPENREGISTER_PATH).');
		}

		$schemas = $this->merged()['components']['schemas'];
		foreach (['ticket', 'lead'] as $schema) {
			$this->assertSame([], $validator->validate($schemas[$schema]), $schema);
		}
	}//end testOpenRegisterAcceptsTheRules()

	/**
	 * The real OpenRegister validator, loaded from a checkout.
	 *
	 * @return object|null The validator, or null when no checkout is found.
	 */
	private function openRegisterValidator(): ?object {
		$class = 'OCA\\OpenRegister\\Service\\Notification\\NotificationAnnotationValidator';
		$root = (string)getenv('OPENREGISTER_PATH');
		if ($root === '') {
			$root = dirname(__DIR__, 4).'/openregister';
		}

		$prefix = 'OCA\\OpenRegister\\Service\\Notification\\';
		$dir = $root.'/lib/Service/Notification/';
		if (is_file($dir.'NotificationAnnotationValidator.php') === false) {
			return null;
		}

		spl_autoload_register(
			static function (string $name) use ($prefix, $dir): void {
				if (str_starts_with($name, $prefix) === true) {
					$file = $dir.str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
					if (is_file($file) === true) {
						require_once $file;
					}
				}
			}
		);

		if (class_exists($class) === false) {
			return null;
		}

		return new $class();
	}//end openRegisterValidator()
}//end class
