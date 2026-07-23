<?php

/**
 * Pipelinq pipelinq:demo:seed command.
 *
 * Idempotent demo-data seed for first-hour evaluation: clients, leads,
 * requests and contactmomenten, linked so lists, dashboards and the 360°
 * client view render populated. Mirrors the procest SeedBezwaarBeroepCommand
 * pattern — occ runs session-less ("Anonymous"), which lacks create rights on
 * the pipelinq schemas, so the command impersonates an admin before seeding.
 * `--remove` deletes exactly the seeded demo set.
 *
 * @category Command
 * @package  OCA\Pipelinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Command;

use OCA\Pipelinq\Service\DemoSeedService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seed (or remove) the pipelinq demo dataset.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class SeedDemoDataCommand extends Command
{
    /**
     * Wire the command against the demo seed service and user/group managers.
     *
     * @param DemoSeedService $demoSeedService Demo dataset seeder.
     * @param IUserSession    $userSession     Session used to impersonate an admin.
     * @param IGroupManager   $groupManager    Resolves an admin to impersonate.
     */
    public function __construct(
        private readonly DemoSeedService $demoSeedService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name, description and the --remove option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'pipelinq:demo:seed')
            ->setDescription('Seed the pipelinq demo dataset (idempotent). Use --remove to delete exactly the seeded set.')
            ->addOption(
                name: 'remove',
                mode: InputOption::VALUE_NONE,
                description: 'Remove the seeded demo objects instead of creating them.'
            );
    }//end configure()

    /**
     * Execute the seed (or removal) and report counts.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Symfony command exit code.
     *
     * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // OpenRegister enforces RBAC against the current user. occ runs with
        // no session ("Anonymous"), which lacks create/delete rights on the
        // pipelinq schemas, so impersonate an admin for the seed.
        if ($this->userSession->getUser() === null) {
            $admin = $this->resolveAdmin();
            if ($admin === null) {
                $output->writeln('<error>No admin user found to run the seed under.</error>');
                return Command::FAILURE;
            }

            $this->userSession->setUser($admin);
            $output->writeln('<comment>Running as admin user "'.$admin->getUID().'".</comment>');
        }

        $remove = (bool) $input->getOption('remove');

        try {
            $result = $this->runMode(remove: $remove);
        } catch (\Throwable $e) {
            $output->writeln('<error>pipelinq:demo:seed failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        if ($result['success'] === false) {
            $output->writeln('<error>pipelinq:demo:seed issue: '.($result['message'] ?? 'unknown error').'</error>');
            return Command::FAILURE;
        }

        $mode = '';
        if ($remove === true) {
            $mode = '--remove ';
        }

        $output->writeln('<info>pipelinq:demo:seed '.$mode.'done</info>');
        foreach (['created', 'skipped', 'removed', 'retained'] as $bucket) {
            if (isset($result[$bucket]) === false) {
                continue;
            }

            foreach ($result[$bucket] as $section => $count) {
                $output->writeln(sprintf('  %-8s %-16s = %d', $bucket, $section, $count));
            }
        }

        return Command::SUCCESS;
    }//end execute()

    /**
     * Run the requested mode against the shared seed service.
     *
     * @param bool $remove Whether to remove instead of seed.
     *
     * @return array<string, mixed> The service result.
     */
    private function runMode(bool $remove): array
    {
        if ($remove === true) {
            return $this->demoSeedService->remove();
        }

        return $this->demoSeedService->seed();
    }//end runMode()

    /**
     * Resolve the first member of the admin group, if any.
     *
     * @return \OCP\IUser|null The admin user to impersonate, or null when none exists.
     */
    private function resolveAdmin(): ?\OCP\IUser
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return null;
        }

        $users = $adminGroup->getUsers();
        if (count($users) === 0) {
            return null;
        }

        return reset($users);
    }//end resolveAdmin()
}//end class
