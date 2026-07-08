<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'domain', 'repository', 'branch', 'root_path', 'web_root_suffix',
        'status', 'deploy_script', 'auto_deploy', 'webhook_token',
        'deploy_key_public', 'ssl_enabled', 'ssl_expires_at',
        'has_scheduler', 'provision_log',
    ];

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

    /** Repository URL rewritten to use the per-site SSH host alias. */
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
        $this->update(['provision_log' => ($this->provision_log ?? '').$chunk]);
    }

    public static function defaultDeployScript(string $rootPath, string $branch): string
    {
        return <<<BASH
        cd {$rootPath}
        git pull origin {$branch}
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
        php artisan migrate --force
        php artisan optimize
        BASH;
    }
}
