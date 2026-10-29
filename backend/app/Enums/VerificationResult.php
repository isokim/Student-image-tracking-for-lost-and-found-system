<?php

namespace App\Enums;

enum VerificationResult: string
{
    case Pending = 'pending';
    case Pass = 'pass';
    case Fail = 'fail';
}
