<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $github_token
 * @property string|null $github_login
 * @property string|null $github_avatar_url
 * @property Carbon|null $github_connected_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'github_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'github_token' => 'encrypted',
            'github_connected_at' => 'datetime',
        ];
    }

    public function hasGitHubConnection(): bool
    {
        return $this->github_token !== null;
    }

    /** Cached repository list for the picker — see GitHubClient::repositories(). */
    public function githubRepositoryCacheKey(): string
    {
        return "github:repositories:{$this->id}";
    }

    /**
     * Drop the stored credentials. Called on disconnect and whenever GitHub
     * answers 401, which means the token was revoked on their side.
     */
    public function clearGitHubConnection(): void
    {
        Cache::forget($this->githubRepositoryCacheKey());

        $this->forceFill([
            'github_token' => null,
            'github_login' => null,
            'github_avatar_url' => null,
            'github_connected_at' => null,
        ])->save();
    }
}
