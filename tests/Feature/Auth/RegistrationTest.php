<?php

declare(strict_types=1);

use App\Actions\RegisterLandlord;
use App\Data\RegistrationDetails;
use App\Enums\LandlordRole;
use App\Enums\NameSuffix;
use App\Enums\UserType;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => Notification::fake());

it('server-renders the sign-up form with the "I want to" question', function (): void {
    $response = $this->get('/register');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Register'));

    expect($response->getContent())
        ->toContain('data-server-rendered="true"')
        ->toMatch('/<legend[^>]*>I want to:<\/legend>/')
        ->toContain('Rent a place')
        ->toContain('List my property');
});

it('registers a renter: unverified, logged in, phone normalized, asked to confirm the email', function (): void {
    $this->post('/register', registrationForm(['suffix' => 'jr']))
        ->assertRedirect('/email/verify');

    $user = User::query()->where('email', 'maria@example.com')->sole();
    expect($user->type)->toBe(UserType::Renter)
        ->and($user->full_name)->toBe('Maria Cristina Dela Cruz Jr.')
        ->and($user->middle_name)->toBeNull()
        ->and($user->suffix)->toBe(NameSuffix::Jr)
        ->and($user->phone)->toBe('09171234567')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->landlordMember()->exists())->toBeFalse();

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('ignores a business name sent with a renter sign-up', function (): void {
    $this->post('/register', registrationForm(['business_name' => 'Should Not Exist']))->assertRedirect('/email/verify');

    expect(LandlordAccount::query()->where('name', 'Should Not Exist')->exists())->toBeFalse();
});

it('registers a landlord with exactly one unverified account and one owner membership', function (): void {
    $accounts = LandlordAccount::query()->count();
    $members = LandlordMember::query()->count();

    $this->post('/register', registrationForm([
        'type' => 'landlord',
        'email' => 'santos@example.com',
        'phone' => '+63 917 765 4321',
        'business_name' => '  Santos   Bedspace Rentals ',
    ]))->assertRedirect('/email/verify');

    $user = User::query()->where('email', 'santos@example.com')->sole();
    expect($user->type)->toBe(UserType::Landlord)
        ->and(LandlordAccount::query()->count())->toBe($accounts + 1)
        ->and(LandlordMember::query()->count())->toBe($members + 1);

    $member = $user->landlordMember()->sole();
    $account = $member->landlordAccount()->sole();
    expect($member->role)->toBe(LandlordRole::Owner)
        ->and($account->name)->toBe('Santos Bedspace Rentals')
        ->and($account->slug)->toBe('santos-bedspace-rentals')
        ->and($account->contact_phone)->toBe('09177654321')
        ->and($account->is_verified)->toBeFalse()
        ->and($account->verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('gives a landlord account a unique slug when the name is taken', function (): void {
    LandlordAccount::factory()->create(['slug' => 'cruz-lettings']);
    LandlordAccount::factory()->create(['slug' => 'cruz-lettings-2']);

    $user = app(RegisterLandlord::class)->handle(details('cruz@example.com'), 'Cruz Lettings');

    expect($user->landlordMember()->sole()->landlordAccount()->sole()->slug)->toBe('cruz-lettings-3');
});

it('falls back to "landlord" for a name with no letters or digits that slug', function (): void {
    $user = app(RegisterLandlord::class)->handle(details('dots@example.com'), '...');

    expect($user->landlordMember()->sole()->landlordAccount()->sole()->slug)->toStartWith('landlord');
});

it('rolls a landlord sign-up back completely when any part fails', function (): void {
    $users = User::query()->count();
    $accounts = LandlordAccount::query()->count();

    // The last step, the owner membership, fails.
    LandlordMember::creating(function (): never {
        throw new RuntimeException('membership insert failed');
    });

    expect(fn () => app(RegisterLandlord::class)->handle(details('rollback@example.com'), 'Rollback Rentals'))
        ->toThrow(RuntimeException::class, 'membership insert failed');

    expect(User::query()->count())->toBe($users)
        ->and(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse()
        ->and(LandlordAccount::query()->count())->toBe($accounts)
        ->and(LandlordAccount::query()->where('name', 'Rollback Rentals')->exists())->toBeFalse();
});

it('rejects an invalid sign-up without creating anything', function (array $overrides, string $field): void {
    User::factory()->create(['email' => 'taken@example.com']);
    $users = User::query()->count();
    $accounts = LandlordAccount::query()->count();

    $this->from('/register')->post('/register', registrationForm($overrides))
        ->assertRedirect('/register')
        ->assertSessionHasErrors($field);

    expect(User::query()->count())->toBe($users)
        ->and(LandlordAccount::query()->count())->toBe($accounts);
    $this->assertGuest();
    Notification::assertNothingSent();
})->with([
    'no account type' => [['type' => null], 'type'],
    'unknown account type' => [['type' => 'admin'], 'type'],
    'no first name' => [['first_name' => ''], 'first_name'],
    'no last name' => [['last_name' => ' '], 'last_name'],
    'unknown suffix' => [['suffix' => 'esq'], 'suffix'],
    'no email' => [['email' => ''], 'email'],
    'not an email' => [['email' => 'maria'], 'email'],
    'email already registered' => [['email' => 'taken@example.com'], 'email'],
    'email already registered, other case' => [['email' => 'Taken@Example.com'], 'email'],
    'no phone' => [['phone' => ''], 'phone'],
    'phone too short' => [['phone' => '0917 123'], 'phone'],
    'landline' => [['phone' => '(02) 8123 4567'], 'phone'],
    'phone with letters' => [['phone' => '0917 CALL ME'], 'phone'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'passwords differ' => [['password_confirmation' => 'something else'], 'password'],
    'landlord without a business name' => [['type' => 'landlord', 'business_name' => ''], 'business_name'],
]);

it('words the errors people hit most in the project voice', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', registrationForm(['email' => 'taken@example.com', 'phone' => '1234', 'type' => null]))
        ->assertSessionHasErrors([
            'email' => 'There is already an account with this email. Log in instead, or reset your password.',
            'phone' => 'Enter a Philippine mobile number, like 0917 123 4567.',
            'type' => 'Choose whether you want to rent a place or list your property.',
        ]);
});

function details(string $email): RegistrationDetails
{
    return new RegistrationDetails('Ana', null, 'Cruz', null, $email, '09171234567', 'correct horse battery');
}
