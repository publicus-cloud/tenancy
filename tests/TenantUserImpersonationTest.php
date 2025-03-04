<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Str;
use Illuminate\Auth\TokenGuard;
use Illuminate\Auth\SessionGuard;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Features\UserImpersonation;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Illuminate\Foundation\Auth\User as Authenticable;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Exceptions\StatefulGuardRequiredException;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/../assets/impersonation-migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    config([
        'tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ],
        'tenancy.features' => [
            UserImpersonation::class,
        ],
    ]);

    Event::listen(
        TenantCreated::class,
        JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    config(['auth.providers.users.model' => ImpersonationUser::class]);
});

test('tenant user can be impersonated on a tenant domain', function () {
    Route::middleware(InitializeTenancyByDomain::class)->group(getRoutes());

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    migrateTenants();
    $user = $tenant->run(function () {
        return ImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    // We try to visit the dashboard directly, before impersonating the user.
    pest()->get('http://foo.localhost/dashboard')
        ->assertRedirect('http://foo.localhost/login');

    // We impersonate the user
    $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');
    pest()->get('http://foo.localhost/impersonate/' . $token->token)
        ->assertRedirect('http://foo.localhost/dashboard');

    // Now we try to visit the dashboard directly, after impersonating the user.
    pest()->get('http://foo.localhost/dashboard')
        ->assertSuccessful()
        ->assertSee('You are logged in as Joe');

    expect(UserImpersonation::isImpersonating())->toBeTrue();
    expect(session('tenancy_impersonating'))->toBeTrue();

    // Leave impersonation
    UserImpersonation::stopImpersonating();

    expect(UserImpersonation::isImpersonating())->toBeFalse();
    expect(session('tenancy_impersonating'))->toBeNull();

    // Assert can't access the tenant dashboard
    pest()->get('http://foo.localhost/dashboard')
        ->assertRedirect('http://foo.localhost/login');
});

test('tenant user can be impersonated on a tenant path', function () {
    makeLoginRoute();

    Route::middleware(InitializeTenancyByPath::class)->prefix('/{tenant}')->group(getRoutes(false));

    $tenant = Tenant::create([
        'id' => 'acme',
        'tenancy_db_name' => 'db' . Str::random(16),
    ]);
    migrateTenants();
    $user = $tenant->run(function () {
        return ImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    // We try to visit the dashboard directly, before impersonating the user.
    pest()->get('/acme/dashboard')
        ->assertRedirect('/login');

    // We impersonate the user
    $token = tenancy()->impersonate($tenant, $user->id, '/acme/dashboard');
    pest()->get('/acme/impersonate/' . $token->token)
        ->assertRedirect('/acme/dashboard');

    // Now we try to visit the dashboard directly, after impersonating the user.
    pest()->get('/acme/dashboard')
        ->assertSuccessful()
        ->assertSee('You are logged in as Joe');

    expect(UserImpersonation::isImpersonating())->toBeTrue();
    expect(session('tenancy_impersonating'))->toBeTrue();

    // Leave impersonation
    UserImpersonation::stopImpersonating();

    expect(UserImpersonation::isImpersonating())->toBeFalse();
    expect(session('tenancy_impersonating'))->toBeNull();

    // Assert can't access the tenant dashboard
    pest()->get('/acme/dashboard')
        ->assertRedirect('/login');
});

test('tokens have a limited ttl', function () {
    Route::middleware(InitializeTenancyByDomain::class)->group(getRoutes());

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    migrateTenants();
    $user = $tenant->run(function () {
        return ImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    // We impersonate the user
    $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');
    $token->update([
        'created_at' => Carbon::now()->subtract(CarbonInterval::make('100s')),
    ]);

    pest()->followingRedirects()
        ->get('http://foo.localhost/impersonate/' . $token->token)
        ->assertStatus(403);
});

test('tokens are deleted after use', function () {
    Route::middleware(InitializeTenancyByDomain::class)->group(getRoutes());

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    migrateTenants();
    $user = $tenant->run(function () {
        return ImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    // We impersonate the user
    $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');

    pest()->assertNotNull(ImpersonationToken::find($token->token));

    pest()->followingRedirects()
        ->get('http://foo.localhost/impersonate/' . $token->token)
        ->assertSuccessful()
        ->assertSee('You are logged in as Joe');

    expect(ImpersonationToken::find($token->token))->toBeNull();
});

test('impersonation works with multiple models and guards', function () {
    config([
        'auth.guards.another' => [
            'driver' => 'session',
            'provider' => 'another_users',
        ],
        'auth.providers.another_users' => [
            'driver' => 'eloquent',
            'model' => AnotherImpersonationUser::class,
        ],
    ]);

    Auth::extend('another', function ($app, $name, array $config) {
        return new SessionGuard($name, Auth::createUserProvider($config['provider']), session());
    });

    Route::middleware(InitializeTenancyByDomain::class)->group(getRoutes(true, 'another'));

    $tenant = Tenant::create();
    $tenant->domains()->create([
        'domain' => 'foo.localhost',
    ]);
    migrateTenants();
    $user = $tenant->run(function () {
        return AnotherImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    // We try to visit the dashboard directly, before impersonating the user.
    pest()->get('http://foo.localhost/dashboard')
        ->assertRedirect('http://foo.localhost/login');

    // We impersonate the user
    $token = tenancy()->impersonate($tenant, $user->id, '/dashboard', 'another');
    pest()->get('http://foo.localhost/impersonate/' . $token->token)
        ->assertRedirect('http://foo.localhost/dashboard');

    // Now we try to visit the dashboard directly, after impersonating the user.
    pest()->get('http://foo.localhost/dashboard')
        ->assertSuccessful()
        ->assertSee('You are logged in as Joe');

    Tenant::first()->run(function () {
        expect(auth()->guard('another')->user()->name)->toBe('Joe');
        expect(auth()->guard('web')->user())->toBe(null);
    });
});

test('impersonation tokens can be created only with stateful guards', function () {
    config([
        'auth.guards' => [
            'nonstateful' => [
                'driver' => 'nonstateful',
                'provider' => 'provider',
            ],
            'stateful' => [
                'driver' => 'session',
                'provider' => 'provider',
            ],
        ],
        'auth.providers.provider' => [
            'driver' => 'eloquent',
            'model' => ImpersonationUser::class,
        ],
    ]);

    $tenant = Tenant::create();
    migrateTenants();

    $user = $tenant->run(function () {
        return ImpersonationUser::create([
            'name' => 'Joe',
            'email' => 'joe@local',
            'password' => bcrypt('secret'),
        ]);
    });

    Auth::extend('nonstateful', fn($app, $name, array $config) => new TokenGuard(Auth::createUserProvider($config['provider']), request()));

    expect(fn() => tenancy()->impersonate($tenant, $user->id, '/dashboard', 'nonstateful'))
        ->toThrow(StatefulGuardRequiredException::class);

    Auth::extend('stateful', fn ($app, $name, array $config) => new SessionGuard($name, Auth::createUserProvider($config['provider']), session()));

    expect(tenancy()->impersonate($tenant, $user->id, '/dashboard', 'stateful'))
        ->toBeInstanceOf(ImpersonationToken::class);
});

function migrateTenants()
{
    pest()->artisan('tenants:migrate')->assertExitCode(0);
}

function makeLoginRoute()
{
    Route::get('/login', function () {
        return 'Please log in';
    })->name('login');
}

function getRoutes($loginRoute = true, $authGuard = 'web'): Closure
{
    return function () use ($loginRoute, $authGuard) {
        if ($loginRoute) {
            makeLoginRoute();
        }

        Route::get('/dashboard', function () use ($authGuard) {
            return 'You are logged in as ' . auth()->guard($authGuard)->user()->name;
        })->middleware('auth:' . $authGuard);

        Route::get('/impersonate/{token}', function ($token) {
            return UserImpersonation::makeResponse($token);
        });
    };
}

class ImpersonationUser extends Authenticable
{
    protected $guarded = [];

    protected $table = 'users';
}

class AnotherImpersonationUser extends Authenticable
{
    protected $guarded = [];

    protected $table = 'users';
}
