<?php

declare(strict_types=1);

namespace App\Federation;

enum LinkOutcome
{
    case Login;
    case LinkedFromAccount;
    case AutoLinked;
    case NeedsConfirmation;
    case Created;
    case NeedsEmail;
}
