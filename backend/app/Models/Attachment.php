<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['attachable_type', 'attachable_id', 'kind', 'path', 'thumb_path'];

    protected $appends = ['url', 'thumb_url'];

    protected $hidden = ['path', 'thumb_path'];

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function getThumbUrlAttribute(): ?string
    {
        return $this->thumb_path ? Storage::disk('public')->url($this->thumb_path) : null;
    }
}
