<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class Role extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    // The seeded super-admin role holds the '*' wildcard; it must never be
    // granted to any other role.
    private const SUPER_ADMIN_ID = 1;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'permissions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'permissions' => 'array',
    ];

    protected static function booted(): void
    {
        // Defence in depth behind RoleResource::canEdit()/RolePolicy: the '*'
        // wildcard grants every permission, so reject saving it onto any role
        // other than the seeded super-admin — otherwise a crafted save (e.g. a
        // hand-built Livewire payload bypassing the CheckboxList's option list)
        // could turn an ordinary role into a full admin.
        static::saving(function (Role $role) {
            if ($role->getKey() !== self::SUPER_ADMIN_ID
                && is_array($role->permissions)
                && in_array('*', $role->permissions, true)) {
                throw new DisplayException('The wildcard permission cannot be assigned to this role.');
            }
        });
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
