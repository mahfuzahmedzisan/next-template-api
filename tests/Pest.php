<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

require_once __DIR__.'/Support/SeoFixtures.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withHeaders([
            'X-BFF-Secret' => (string) config('services.frontend.bff_secret'),
            'X-Frontend-Origin' => 'http://localhost:3000',
        ]);

        // Settings saves rebuild sitemap/robots/llms; keep them off the real disk.
        Storage::fake((string) config('seo.files_disk'));
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * `Sanctum::actingAs` returns the user, so this hands back the test case to
 * keep request chains like `actingAsSanctum($admin)->postJson(...)`.
 */
function actingAsSanctum(User $user, array $abilities = ['*']): mixed
{
    Sanctum::actingAs($user, $abilities);

    return test();
}

function enableConfirmedTwoFactor(User $user, string $recoveryCode = 'recovery-code-alpha'): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('TEST2FASECRETTEST2FASECRETTE'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([$recoveryCode])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user->fresh();
}
