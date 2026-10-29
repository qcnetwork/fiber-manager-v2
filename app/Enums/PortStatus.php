<?php

namespace App\Enums;

enum PortStatus: string
{
    case Kosong = 'kosong';
    case Terpakai = 'terpakai';
    case Booking = 'booking';
    case Rusak = 'rusak';
}
