<?php

/**
 * Test stub for OpenRegister's EmailMessageRef.
 *
 * Mirrors the value object EmailLinkService::linkEmail() takes as its message;
 * OpenRegister is not a test-time dependency.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal EmailMessageRef stub: a Mail message by account, id and optional uid.
 */
final class EmailMessageRef {

	/**
	 * Message uid; '' when not given.
	 *
	 * @var string
	 */
	private string $uid = '';

	/**
	 * Constructor.
	 *
	 * @param int    $mailAccountId Mail account id.
	 * @param string $messageId     Mail message id.
	 */
	public function __construct(
		public readonly int $mailAccountId,
		public readonly string $messageId,
	) {
	}//end __construct()

	/**
	 * A copy with the given uid.
	 *
	 * @param string $uid The uid; '' for none.
	 *
	 * @return self The copy.
	 */
	public function withUid(string $uid): self {
		$copy      = clone $this;
		$copy->uid = $uid;
		return $copy;
	}//end withUid()

	/**
	 * The uid to match on, or null when none was given.
	 *
	 * @return string|null The uid.
	 */
	public function uidForMatch(): ?string {
		if ($this->uid === '') {
			return null;
		}

		return $this->uid;
	}//end uidForMatch()
}//end class
