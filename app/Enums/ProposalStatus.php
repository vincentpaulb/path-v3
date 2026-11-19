<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case EVALUATION = 'Ongoing Evaluation';
    case APPROVED = 'Approved';
    case DISAPPROVED = 'Disapproved';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
