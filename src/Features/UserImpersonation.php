<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Tenancy;

class UserImpersonation implements Feature
{
    /** The lifespan of impersonation tokens (in seconds). */
    public static int $ttl = 60;

    public function bootstrap(Tenancy $tenancy): void
    {
        $tenancy->macro('impersonate', function (Tenant $tenant, string $userId, string $redirectUrl, string|null $authGuard = null, bool $remember = false): Model {
            return UserImpersonation::modelClass()::create([
                Tenancy::tenantKeyColumn() => $tenant->getTenantKey(),
                'user_id' => $userId,
                'redirect_url' => $redirectUrl,
                'auth_guard' => $authGuard,
                'remember' => $remember,
            ]);
        });
    }

    /** Impersonate a user and get an HTTP redirect response. */
    public static function makeResponse(#[\SensitiveParameter] string|Model $token, ?int $ttl = null): RedirectResponse
    {
        /**
         * The model does NOT have to extend ImpersonationToken, but usually it WILL be a child
         * of ImpersonationToken and this makes it clear to phpstan that the model has a redirect_url property.
         *
         * @var ImpersonationToken $token
         */
        $token = $token instanceof Model ? $token : static::modelClass()::findOrFail($token);
        $ttl ??= static::$ttl;

        $tokenExpired = $token->created_at->diffInSeconds(now()) > $ttl;

        abort_if($tokenExpired, 403);

        $tokenTenantId = (string) $token->getAttribute(Tenancy::tenantKeyColumn());
        $currentTenantId = (string) tenant()->getTenantKey();

        abort_unless($tokenTenantId === $currentTenantId, 403);

        Auth::guard($token->auth_guard)->loginUsingId($token->user_id, $token->remember);

        $token->delete();

        session()->put('tenancy_impersonating', true);

        return redirect($token->redirect_url);
    }

    /** @return class-string<Model> */
    public static function modelClass(): string
    {
        return config('tenancy.models.impersonation_token');
    }

    public static function isImpersonating(): bool
    {
        return session()->has('tenancy_impersonating');
    }

    /**
     * Logout from the current domain and forget impersonation session.
     */
    public static function stopImpersonating(): void
    {
        auth()->logout();

        session()->forget('tenancy_impersonating');
    }
}
