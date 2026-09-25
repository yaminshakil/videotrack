<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** A channel manager: adds topics to their assigned channels and assigns them to employees. */
class Manager extends Authenticatable
{
    protected $fillable = ['name', 'username', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** The channels this manager is allowed to work in. */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }

    public function manages(Channel $channel): bool
    {
        return $this->channels()->whereKey($channel->id)->exists();
    }
}
