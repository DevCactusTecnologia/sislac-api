<?php

namespace App\Models;

use App\Platform\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Laboratory extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = [
        'name',
        'code',
        'status',
        'database_url',
    ];

    protected $hidden = [
        'database_url',
    ];

    protected function casts(): array
    {
        return [
            'database_url' => 'encrypted',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
