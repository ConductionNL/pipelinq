<?php

/**
 * Pipelinq ContactsSyncCommand.
 *
 * `occ pipelinq:contacts:sync` writes a Nextcloud Contacts card for every
 * client under the uid the client names, in the addressbook of the account
 * it runs as (`--user`, default the first admin).
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Kernel\Contracts\ClientContactCardSyncInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a Contacts card per client with the client's uid.
 *
 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
 */
class ContactsSyncCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ClientContactCardSyncInterface $sync         The card sync.
	 * @param IUserSession                   $userSession  The session the cards are written as.
	 * @param IUserManager                   $userManager  Resolves --user.
	 * @param IGroupManager                  $groupManager Finds the first admin.
	 */
	public function __construct(
		private readonly ClientContactCardSyncInterface $sync,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'pipelinq:contacts:sync')
			->setDescription('Write a Contacts card for every client under the uid the client names.')
			->addOption(name: 'user', mode: InputOption::VALUE_REQUIRED, description: 'The account whose addressbook takes new cards (default: the first admin).')
			->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'List the cards that would be written without writing them.');
	}//end configure()

	/**
	 * Run the sync as the chosen account and print the report.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The exit code: 0 when no card failed.
	 *
	 * @spec openspec/changes/example-set-zuiddrecht/tasks.md#task-2.5
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $this->actingUser(requested: $input->getOption('user'));
		if ($user === null) {
			$output->writeln('<error>No account to write the cards as: pass --user with an existing account.</error>');
			return Command::FAILURE;
		}

		$this->userSession->setUser($user);
		$dryRun = ($input->getOption('dry-run') === true);
		try {
			$report = $this->sync->run(dryRun: $dryRun);
		} catch (\Throwable $e) {
			$output->writeln('<error>Writing the contact cards failed: '.$e->getMessage().'</error>');
			return Command::FAILURE;
		}

		$this->printReport(report: $report, dryRun: $dryRun, output: $output);
		if ($report['failed'] !== []) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The account named by --user, else the signed-in one, else the first admin.
	 *
	 * @param mixed $requested The --user value.
	 *
	 * @return IUser|null The account, or null when none is found.
	 */
	private function actingUser(mixed $requested): ?IUser {
		if (is_string($requested) === true && $requested !== '') {
			return $this->userManager->get($requested);
		}

		$current = $this->userSession->getUser();
		if ($current !== null) {
			return $current;
		}

		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];
		if ($admins === []) {
			return null;
		}

		return reset($admins);
	}//end actingUser()

	/**
	 * Print one line per client written, skipped or failed, then the counts.
	 *
	 * @param array<string, list<array<string, string>>> $report The sync report.
	 * @param bool                                       $dryRun Whether nothing was written.
	 * @param OutputInterface                            $output The output.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 */
	private function printReport(array $report, bool $dryRun, OutputInterface $output): void {
		$verb = 'Wrote';
		if ($dryRun === true) {
			$verb = 'Would write';
		}

		foreach ($report['written'] as $client) {
			$output->writeln(sprintf('%s the card %s for "%s"', $verb, $client['uid'], $client['name']));
		}

		foreach ($report['skipped'] as $client) {
			$output->writeln(sprintf('Skipped "%s" (%s): it names no contactsUid', $client['name'], $client['id']));
		}

		foreach ($report['failed'] as $client) {
			$output->writeln(sprintf('<error>Could not write the card %s for "%s"</error>', $client['uid'], $client['name']));
		}

		$counts = [count($report['written']), count($report['skipped']), count($report['failed'])];
		$output->writeln(vsprintf('<info>%d written, %d skipped, %d failed.</info>', $counts));
	}//end printReport()
}//end class
