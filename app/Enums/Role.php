<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case AdminArea = 'admin_area';
    case Teknisi = 'teknisi';
    case Viewer = 'viewer';
    case Sales = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminArea => 'Admin Area',
            self::Teknisi => 'Teknisi',
            self::Viewer => 'Viewer',
            self::Sales => 'Sales (cek coverage)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()], self::cases());
    }
}
