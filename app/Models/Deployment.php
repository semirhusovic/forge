<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'site_id', 'status', 'trigger', 'commit_hash', 'commit_message',
        'output', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function appendOutput(string $chunk): void
    {
        $this->update(['output' => ($this->output ?? '').$chunk]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [DeploymentStatus::Pending, DeploymentStatus::Running], true);
    }
}
