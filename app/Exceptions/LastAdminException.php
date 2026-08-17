<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ChangeUserRole when a role change would leave zero administrators.
 * UserPolicy::updateRole covers only self-demotion; this backs the case a policy
 * cannot express — the whole admin set, re-counted under a lock. Rendered as 403,
 * the same status as the policy check, because it is the same rule.
 */
final class LastAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This would leave the system with no administrators.');
    }
}
