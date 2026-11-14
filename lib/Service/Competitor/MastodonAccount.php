<?php

/**
 * Pipelinq MastodonAccount
 *
 * A Mastodon account found by its handle, with the id its instance gave it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

/**
 * The `user@instance` handle and the account id.
 *
 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */
final class MastodonAccount {

	/**
	 * Constructor.
	 *
	 * @param string $handle `user@instance`.
	 * @param string $id     The account id on that instance.
	 */
	public function __construct(
		public readonly string $handle,
		public readonly string $id,
	) {
	}//end __construct()

	/**
	 * The instance's base URL.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function base(): string {
		return ('https://' . explode('@', $this->handle, 2)[1]);
	}//end base()
}//end class
