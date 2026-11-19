<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'area_id',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'role' => 'teknisi',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    /**
     * Only super admins manage users, so their history is not tied to an area.
     */
    public function activityAreaId(): ?int
    {
        return null;
    }

    protected function activityIgnored(): array
    {
        return ['remember_token', 'email_verified_at'];
    }

    protected function activityMasked(): array
    {
        return ['password'];
    }

    /**
     * Users without an area work across all areas (super admins always do) and pick the area when adding data.
     */
    public function seesAllAreas(): bool
    {
        return $this->area_id === null;
    }

    /**
     * Areas (POPs) have no area scope of their own; this is the check for them.
     */
    public function canAccessArea(int $areaId): bool
    {
        return $this->seesAllAreas() || $this->area_id === $areaId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    /**
     * Super admins and helpdesk manage areas (POPs) and the coverage radius; only super admins manage users.
     */
    public function isAdministrator(): bool
    {
        return in_array($this->role, [Role::SuperAdmin, Role::Helpdesk], true);
    }

    /**
     * The only super admin left: deleting or demoting them would leave nobody to manage users and areas.
     */
    public function isLastSuperAdmin(): bool
    {
        return $this->isSuperAdmin() && ! static::where('role', Role::SuperAdmin)->whereKeyNot($this->getKey())->exists();
    }

    /**
     * Sales accounts only check coverage and never see customer data.
     */
    public function isSales(): bool
    {
        return $this->role === Role::Sales;
    }

    /**
     * Whether the user may add and edit network data (ODC, ODP, ports, customers).
     */
    public function canEditNetwork(): bool
    {
        return in_array($this->role, [Role::SuperAdmin, Role::Helpdesk, Role::AdminArea, Role::Teknisi], true);
    }

    /**
     * Technicians' changes wait for approval instead of being saved straight away.
     */
    public function needsApproval(): bool
    {
        return $this->role === Role::Teknisi;
    }

    /**
     * Super admins approve requests from every area, area admins those of their own area.
     */
    public function canApprove(): bool
    {
        return in_array($this->role, [Role::SuperAdmin, Role::Helpdesk, Role::AdminArea], true);
    }

    /**
     * Whether the user may delete network data.
     */
    public function canDeleteNetwork(): bool
    {
        return in_array($this->role, [Role::SuperAdmin, Role::Helpdesk, Role::AdminArea], true);
    }
}
