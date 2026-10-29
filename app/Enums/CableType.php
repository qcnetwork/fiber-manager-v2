<?php

namespace App\Enums;

enum CableType: string
{
    case Feeder = 'feeder';
    case Distribusi = 'distribusi';
    case Drop = 'drop';
}
