<?php

// app/Exceptions/LastAdminException.php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ChangeUserRole when applying a role change would leave the
 * system with zero administrators.
 *
 * UserPolicy::updateRole enforces the same invariant for the single case of
 * an admin acting on themselves — a check that only holds against one row
 * and needs no lock, since a user's own id never changes underneath them.
 * This exception backs the broader case that check cannot express: the
 * whole admin set, re-counted under a lock inside the same transaction that
 * writes it, so two different admins concurrently demoting each other
 * cannot both succeed and land the system on zero. Rendered as 403 — the
 * same status the self-demotion policy check already returns, because it is
 * the same rule.
 */
final class LastAdminException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This would leave the system with no administrators.');
    }
}
