<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventImage extends Model
{
    protected $guarded = [];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Public, locally-served URL for the stored file. Root-relative so it resolves
     * against whatever host/port serves the app (not a hardcoded APP_URL), while
     * still pointing at the public-disk symlink (php artisan storage:link).
     */
    public function getUrlAttribute(): string
    {
        return '/storage/'.ltrim($this->path, '/');
    }
}
