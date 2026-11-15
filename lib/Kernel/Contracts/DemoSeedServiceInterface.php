<?php

/**
 * Contract of DemoSeedService
 *
 * The public surface of DemoSeedService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of DemoSeedService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DemoSeedServiceInterface {

	/**
	 * The answer that means "seed nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app seeds.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so what the card
	 * says is what this service will actually create.
	 *
	 * `objectCount` is 0 because this seeder BUILDS its objects rather than
	 * importing a file, so there is no honest number until it has run. The card
	 * shows no count rather than a made-up one.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array;

	/**
	 * Remove exactly the seeded demo set (lookup-field exact match, [Demo] prefix guarded).
	 *
	 * Archival (append-only) schemas forbid user-driven deletes by design; their
	 * demo rows are reported as `retained` and expire via the OpenRegister
	 * ArchivalRetentionTask cron instead.
	 *
	 * @return array{success: bool, message?: string, removed: array<string, int>, retained: array<string, int>}
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(): array;

	/**
	 * Seed the demo dataset (idempotent: existing demo objects are reused).
	 *
	 * @return array{success: bool, message?: string, created: array<string, int>, skipped: array<string, int>, relinked?: array<string, int>}
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(): array;
}//end interface
