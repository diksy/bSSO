<?php

declare(strict_types=1);

namespace App\Federation;

use App\Repository\Record\User;

final class LinkResult
{
    public function __construct(public readonly LinkOutcome $outcome, public readonly ?User $user = null)
    {
    }
}
