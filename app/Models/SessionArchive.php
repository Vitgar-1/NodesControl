<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionArchive extends Model
{
    public $timestamps = false;

    protected $table = 'session_archive';

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'clicks',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}