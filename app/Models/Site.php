<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain
 * @property string $repository
 * @property string $branch
 * @property string $root_path
 * @property string $web_root_suffix
 * @property SiteStatus $status
 * @property string $deploy_script
 * @property bool $auto_deploy
 * @property string $webhook_token
 * @property string|null $deploy_key_public
 * @property bool $ssl_enabled
 * @property Carbon|null $ssl_expires_at
 * @property bool $has_scheduler
 * @property string|null $provision_log
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'domain', 'repository', 'branch', 'root_path', 'web_root_suffix',
    'status', 'deploy_script', 'auto_deploy', 'webhook_token',
    'deploy_key_public', 'ssl_enabled', 'ssl_expires_at',
    'has_scheduler', 'provision_log',
])]
class Site extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'auto_deploy' => 'boolean',
            'ssl_enabled' => 'boolean',
            'has_scheduler' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->latest();
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /** SSH host alias so each site uses its own deploy key. */
    public function gitHostAlias(): string
    {
        return 'github.com-site-'.$this->id;
    }

    /**
     * Repository URL rewritten to use the per-site SSH host alias.
     * GitHub-only by design — enforced at site creation validation.
     */
    public function cloneUrl(): string
    {
        return preg_replace('/github\.com/', $this->gitHostAlias(), $this->repository, 1);
    }

    public function webhookUrl(): string
    {
        return route('webhook.deploy', ['site' => $this->id, 'token' => $this->webhook_token]);
    }

    public function webRoot(): string
    {
        return $this->root_path.$this->web_root_suffix;
    }

    public function appendProvisionLog(string $chunk): void
    {
        // Atomic SQL append — a read-modify-write here would lose chunks if
        // another process wrote between the read and the write.
        $this->newQuery()->whereKey($this->getKey())->update([
            'provision_log' => $this->getConnection()->raw(
                "COALESCE(provision_log, '') || ".$this->getConnection()->getPdo()->quote($chunk)
            ),
        ]);

        $this->provision_log = ($this->provision_log ?? '').$chunk;
    }

    public static function defaultDeployScript(string $rootPath, string $branch): string
    {
        return <<<BASH
        cd {$rootPath}
        git pull origin {$branch}
        composer update --no-dev --no-interaction --prefer-dist --optimize-autoloader
        php artisan migrate --force
        php artisan optimize
        BASH;
    }
}
