<?php

/**
 * PHPStan declaration-only stub for OCA\Mail\Db\LocalMessage.
 *
 * See `tests/Stubs/Mail/Service/AccountService.php` for why this stub
 * exists and why it is safe: never autoloaded, phpstan-only.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Mail\Db
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Mail\Db;

if (class_exists(LocalMessage::class) === false) {
	/**
	 * Declaration-only stub — never instantiated, never autoloaded.
	 */
	class LocalMessage {
		/**
		 * @param string $subject Message subject.
		 *
		 * @return void
		 */
		public function setSubject(string $subject): void {
		}//end setSubject()

		/**
		 * @param string $body Plain-text body.
		 *
		 * @return void
		 */
		public function setBodyPlain(string $body): void {
		}//end setBodyPlain()

		/**
		 * @param bool $isHtml Whether the body is HTML.
		 *
		 * @return void
		 */
		public function setHtml(bool $isHtml): void {
		}//end setHtml()

		/**
		 * @param string $body HTML body.
		 *
		 * @return void
		 */
		public function setBodyHtml(string $body): void {
		}//end setBodyHtml()
	}//end class
}//end if
