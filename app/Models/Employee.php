<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Employee extends Authenticatable
{
    protected $fillable = ['name', 'username', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'assigned_to');
    }

    /** Topics this employee added themselves, as opposed to ones assigned by an admin. */
    public function addedTopics(): HasMany
    {
        return $this->hasMany(Topic::class, 'added_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(Bonus::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(Rate::class);
    }
}
