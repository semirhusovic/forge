<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property DeploymentStatus $status
 * @property string $trigger
 * @property string|null $commit_hash
 * @property string|null $commit_message
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'site_id', 'status', 'trigger', 'commit_hash', 'commit_message',
    'output', 'started_at', 'finished_at',
])]
class Deployment extends Model
{
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
        // Atomic SQL append — a read-modify-write here would lose chunks if
        // another process wrote between the read and the write.
        $this->newQuery()->whereKey($this->getKey())->update([
            'output' => $this->getConnection()->raw(
                "COALESCE(output, '') || ".$this->getConnection()->getPdo()->quote($chunk)
            ),
        ]);

        $this->output = ($this->output ?? '').$chunk;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [DeploymentStatus::Pending, DeploymentStatus::Running], true);
    }
}
