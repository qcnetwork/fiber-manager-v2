<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Aktif = 'aktif';
    case Rencana = 'rencana';
    case Rusak = 'rusak';
}
