<?php

namespace App\Enums;

enum SpliceBoxType: string
{
    case JointBox = 'joint_box';
    case Roset = 'roset';

    public function label(): string
    {
        return match ($this) {
            self::JointBox => 'Joint box',
            self::Roset => 'Roset',
        };
    }
}
