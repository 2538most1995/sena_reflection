<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'username', 'password', 'role', 'tambon', 'avatar_path'];

    protected $hidden = ['password'];

    public function tambonRelation(): BelongsTo
    {
        return $this->belongsTo(Tambon::class, 'tambon');
    }

    public function isDistrict(): bool
    {
        return $this->role === 'district';
    }
}
