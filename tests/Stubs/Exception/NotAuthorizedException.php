<?php

/**
 * Test stub of OpenRegister's NotAuthorizedException.
 *
 * OpenRegister raises it when the acting user may not do what was asked, for
 * instance read an object. Same name and parent as the real class, so a
 * `catch (NotAuthorizedException)` in app code is exercised as it runs live.
 *
 * @category Test
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Stub: the acting user is not authorised.
 */
class NotAuthorizedException extends Exception {
}//end class
