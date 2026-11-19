<?php

namespace App\Enums;

enum FundedProjectStatus: string
{
    case NEW = 'New';
    case ONGOING = 'Ongoing';
    case STOPPED_PAYMENT = 'Stopped Payment';
    case COMPLETED = 'Completed';
    case GRADUATED = 'Graduated';
    case TERMINATED = 'Terminated';
    case WITHDRAWN = 'Withdrawn';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
