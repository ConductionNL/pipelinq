<?php

/**
 * A colleague added to a ticket or lead can work on it.
 *
 * Merges the real monolith and the real register.d fragments through
 * ConfigFileLoaderService and reads the ticket and lead authorization blocks
 * as the install imports them, then runs the collaborator rule through
 * OpenRegister's own ConditionMatcher when a checkout is at hand.
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
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Read and update admit the listed colleagues; create, delete and export do not.
 *
 * @spec openspec/changes/notify-the-followers-of-tickets-and-leads/specs/notifications-activity/spec.md#requirement-a-colleague-added-to-a-ticket-or-lead-can-work-on-it-req-nftl-005
 */
class CollaboratorAccessTest extends TestCase {
	/**
	 * The rule that admits a colleague listed on the record.
	 *
	 * @var array<string, mixed>
	 */
	private const RULE = [
		'group' => 'authenticated',
		'match' => ['collaborators' => ['$contains' => '$userId']],
	];

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
	 * The staff block the register-access fragment gives a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function staffBlock(string $schema): array {
		$file = dirname(__DIR__, 3).'/lib/Settings/register.d/99-zzz-register-access.json';
		$data = json_decode((string)file_get_contents($file), true);

		return $data['components']['schemas'][$schema]['authorization'];
	}//end staffBlock()

	/**
	 * Read and update keep every staff grant and add the collaborator rule once.
	 *
	 * @return void
	 */
	public function testReadAndUpdateAdmitTheListedColleagues(): void {
		$schemas = $this->merged()['components']['schemas'];
		foreach (['ticket', 'lead'] as $schema) {
			$staff = $this->staffBlock(schema: $schema);
			foreach (['read', 'update'] as $verb) {
				$this->assertSame(
					array_merge($staff[$verb], [self::RULE]),
					$schemas[$schema]['authorization'][$verb],
					"{$schema}.{$verb}"
				);
			}
		}
	}//end testReadAndUpdateAdmitTheListedColleagues()

	/**
	 * A colleague may not create, delete or export through being listed.
	 *
	 * @return void
	 */
	public function testOtherVerbsStayWithTheStaff(): void {
		$schemas = $this->merged()['components']['schemas'];
		foreach (['ticket', 'lead'] as $schema) {
			$staff = $this->staffBlock(schema: $schema);
			foreach (['create', 'delete', 'export'] as $verb) {
				$this->assertSame($staff[$verb], $schemas[$schema]['authorization'][$verb], "{$schema}.{$verb}");
			}
		}
	}//end testOtherVerbsStayWithTheStaff()

	/**
	 * The record lists its colleagues as Nextcloud user ids.
	 *
	 * @return void
	 */
	public function testCollaboratorsIsAListOfUsers(): void {
		$schemas = $this->merged()['components']['schemas'];
		foreach (['ticket', 'lead'] as $schema) {
			$property = $schemas[$schema]['properties']['collaborators'];
			$this->assertSame('array', $property['type'], $schema);
			$this->assertSame(['type' => 'string', 'format' => 'user'], $property['items'], $schema);
		}
	}//end testCollaboratorsIsAListOfUsers()

	/**
	 * OpenRegister admits Jeroen while he is listed and refuses him once he is taken off.
	 *
	 * Needs an OpenRegister checkout: OPENREGISTER_PATH, or `openregister`
	 * next to this app. Without one the test is skipped.
	 *
	 * @return void
	 */
	public function testOpenRegisterAdmitsOnlyWhileListed(): void {
		$matcher = $this->openRegisterMatcher(uid: 'jeroen');
		if ($matcher === null) {
			$this->markTestSkipped('No OpenRegister checkout with ConditionMatcher found (set OPENREGISTER_PATH).');
		}

		$match = self::RULE['match'];
		$this->assertTrue($matcher->objectMatchesConditions(['collaborators' => ['sanne', 'jeroen']], $match));
		$this->assertFalse($matcher->objectMatchesConditions(['collaborators' => ['sanne']], $match));
		$this->assertFalse($matcher->objectMatchesConditions(['collaborators' => []], $match));
		$this->assertFalse($matcher->objectMatchesConditions(['collaborators' => null], $match));
	}//end testOpenRegisterAdmitsOnlyWhileListed()

	/**
	 * The real OpenRegister condition matcher for a signed-in user, loaded from a checkout.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return object|null The matcher, or null when no checkout is found.
	 */
	private function openRegisterMatcher(string $uid): ?object {
		$prefix = 'OCA\\OpenRegister\\';
		$root = (string)getenv('OPENREGISTER_PATH');
		if ($root === '') {
			$root = dirname(__DIR__, 4).'/openregister';
		}

		$dir = $root.'/lib/';
		foreach (['ConditionMatcher', 'ConditionUserResolvers', 'OperatorEvaluator'] as $name) {
			if (is_file($dir.'Service/'.$name.'.php') === false) {
				return null;
			}
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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$users = new ('OCA\\OpenRegister\\Service\\ConditionUserResolvers')($session);
		$evaluator = new ('OCA\\OpenRegister\\Service\\OperatorEvaluator')(new NullLogger());

		return new ('OCA\\OpenRegister\\Service\\ConditionMatcher')(
			$users,
			$this->createMock(ContainerInterface::class),
			$evaluator,
			new NullLogger()
		);
	}//end openRegisterMatcher()
}//end class
