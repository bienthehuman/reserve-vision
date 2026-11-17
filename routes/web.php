<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Invitations\AcceptInvitationController;
use App\Http\Controllers\Invitations\ShowInvitation;
use App\Http\Controllers\Landlord\AcceptViewingController;
use App\Http\Controllers\Landlord\ArchivePropertyController;
use App\Http\Controllers\Landlord\ArchiveRoomController;
use App\Http\Controllers\Landlord\ArchiveSpaceController;
use App\Http\Controllers\Landlord\ConfirmReservationController;
use App\Http\Controllers\Landlord\DashboardController;
use App\Http\Controllers\Landlord\DeclineReservationController;
use App\Http\Controllers\Landlord\DeclineViewingController;
use App\Http\Controllers\Landlord\DeletePropertyPhotoController;
use App\Http\Controllers\Landlord\DescribePropertyPhotoController;
use App\Http\Controllers\Landlord\EditProperty;
use App\Http\Controllers\Landlord\EditRoom;
use App\Http\Controllers\Landlord\EndStayController;
use App\Http\Controllers\Landlord\ListMembers;
use App\Http\Controllers\Landlord\ListProperties;
use App\Http\Controllers\Landlord\ListReservations;
use App\Http\Controllers\Landlord\ListViewings;
use App\Http\Controllers\Landlord\MarkMovedInController;
use App\Http\Controllers\Landlord\MarkViewingDoneController;
use App\Http\Controllers\Landlord\MovePropertyPhotoController;
use App\Http\Controllers\Landlord\NewProperty;
use App\Http\Controllers\Landlord\NewRoom;
use App\Http\Controllers\Landlord\PublishPropertyController;
use App\Http\Controllers\Landlord\RecordRefundController;
use App\Http\Controllers\Landlord\RejectProofController;
use App\Http\Controllers\Landlord\RemoveMemberController;
use App\Http\Controllers\Landlord\RenameSpaceController;
use App\Http\Controllers\Landlord\ResendInvitationController;
use App\Http\Controllers\Landlord\RetryPropertyPhotoController;
use App\Http\Controllers\Landlord\RevokeInvitationController;
use App\Http\Controllers\Landlord\SaveProperty;
use App\Http\Controllers\Landlord\SaveRoom;
use App\Http\Controllers\Landlord\StoreInvitation;
use App\Http\Controllers\Landlord\StoreProperty;
use App\Http\Controllers\Landlord\StorePropertyPhoto;
use App\Http\Controllers\Landlord\StoreRoom;
use App\Http\Controllers\Landlord\StoreSpace;
use App\Http\Controllers\Landlord\UnarchivePropertyController;
use App\Http\Controllers\Landlord\UnarchiveRoomController;
use App\Http\Controllers\Landlord\UnarchiveSpaceController;
use App\Http\Controllers\Landlord\UndoConfirmationController;
use App\Http\Controllers\Landlord\UnpublishPropertyController;
use App\Http\Controllers\Landlord\ViewProperty;
use App\Http\Controllers\Landlord\ViewReservation;
use App\Http\Controllers\Landlord\ViewViewing;
use App\Http\Controllers\Landlord\WithdrawReservationController;
use App\Http\Controllers\Listings\CountListings;
use App\Http\Controllers\Listings\ListListings;
use App\Http\Controllers\Listings\ShowListing;
use App\Http\Controllers\Listings\ShowRobots;
use App\Http\Controllers\Listings\ShowSitemap;
use App\Http\Controllers\Media\ShowLocalPhoto;
use App\Http\Controllers\Proofs\DownloadProof;
use App\Http\Controllers\Proofs\ShowProof;
use App\Http\Controllers\Reservations\CancelReservationController;
use App\Http\Controllers\Reservations\ListOwnReservations;
use App\Http\Controllers\Reservations\ShowReservation;
use App\Http\Controllers\Reservations\StorePaymentProof;
use App\Http\Controllers\Reservations\StoreReservation;
use App\Http\Controllers\Rooms\RedirectRoom;
use App\Http\Controllers\StyleguideController;
use App\Http\Controllers\Viewings\CancelViewingController;
use App\Http\Controllers\Viewings\ListOwnViewings;
use App\Http\Controllers\Viewings\StoreViewing;
use App\Http\Middleware\ExposeRequestTiming;
use App\Http\Middleware\KeepReservationIntent;
use App\Http\Middleware\KeepViewingIntent;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/styleguide', StyleguideController::class)->name('styleguide');

require __DIR__.'/auth.php';

// Resized property photos (slice 4.2). In production Caddy serves this path from the photos bucket and the
// request never reaches the app; with MEDIA_STORAGE=local the app streams the file from the photos disk.
if (config('filesystems.disks.photos.driver') === 'local') {
    Route::get('/media/photos/{path}', ShowLocalPhoto::class)->where('path', '.+')->name('media.photos.show');
}

// Public listing pages (slice 5.1). Only published properties of verified accounts: anything else is 404.
Route::get('/listings', ListListings::class)->name('listings.index');
Route::get('/listings/count', CountListings::class)->name('listings.count');
Route::get('/listings/{city}/{slug}', ShowListing::class)->name('listings.show');
Route::get('/sitemap.xml', ShowSitemap::class)->name('sitemap');
Route::get('/robots.txt', ShowRobots::class)->name('robots');

// The 2.5 room page moved onto its listing page: a permanent redirect to the room's anchor. Only listed rooms.
Route::get('/rooms/{room}', RedirectRoom::class)->whereNumber('room')->name('rooms.show');

// Renters reserve and see their own reservations (RESERVATIONS.md). Landlords get 403.
Route::middleware(['auth', 'verified', 'renter'])->group(function (): void {
    Route::post('/rooms/{room}/reservations', StoreReservation::class)
        ->whereNumber('room')
        // KeepReservationIntent runs before `auth` (bootstrap/app.php): a guest comes back to the room with the stay.
        // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production).
        ->middleware([KeepReservationIntent::class, 'throttle:reservations', ExposeRequestTiming::class])
        ->name('rooms.reservations.store');

    // Slice 5.3: my reservations, the hold page, sending a proof, cancelling. Another renter's reservation is a 404.
    Route::get('/reservations', ListOwnReservations::class)->name('reservations.index');
    Route::get('/reservations/{reservation}', ShowReservation::class)->whereNumber('reservation')->name('reservations.show');
    Route::post('/reservations/{reservation}/proofs', StorePaymentProof::class)->whereNumber('reservation')->name('reservations.proofs.store');
    // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production): the confirm-versus-cancel race (6.1).
    Route::post('/reservations/{reservation}/cancel', CancelReservationController::class)->whereNumber('reservation')->middleware(ExposeRequestTiming::class)->name('reservations.cancel');

    // Slice 6.2: viewing requests. KeepViewingIntent runs before `auth` (bootstrap/app.php): a guest comes back to the listing's form.
    Route::post('/viewings', StoreViewing::class)->middleware([KeepViewingIntent::class, 'throttle:reservations'])->name('viewings.store');
    Route::get('/viewings', ListOwnViewings::class)->name('viewings.index');
    Route::post('/viewings/{viewing}/cancel', CancelViewingController::class)->whereNumber('viewing')->name('viewings.cancel');
});

// Payment proofs (slice 5.3, docs/ACCESS.md "Private files"). The renter whose proof it is and the members of
// its account open `/proofs/{proof}`, which checks the policy and redirects to a signed URL valid 5 minutes for
// that user only. The file route has no `auth` middleware on purpose: a guest gets 403 there, not a login page.
Route::get('/proofs/{proof}', ShowProof::class)->whereNumber('proof')->middleware(['auth', 'verified'])->name('proofs.show');
Route::get('/proofs/{proof}/file', DownloadProof::class)->whereNumber('proof')->middleware('signed:relative')->name('proofs.file');

// An invitation link from an email (slice 4.3). Public: the token is the key, stored only as a hash, and
// a link that can't be used gets one neutral page. Rate limited per IP (AppServiceProvider), so tokens can't
// be guessed at speed. ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production).
Route::get('/invitations/{token}', ShowInvitation::class)
    ->where('token', '[A-Za-z0-9]{1,128}')->middleware('throttle:invitations')->name('invitations.show');
Route::post('/invitations/{token}', AcceptInvitationController::class)
    ->where('token', '[A-Za-z0-9]{1,128}')->middleware(['throttle:invitation-accept', ExposeRequestTiming::class])->name('invitations.accept');

// Landlord pages: logged in, email verified, and a landlord user (ACCESS.md).
Route::middleware(['auth', 'verified', 'landlord'])->prefix('landlord')->name('landlord.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Slice 3.2. Record parameters bind through the isolation scope: another account's id is a 404.
    // tests/Feature/Isolation/T6CrossAccessTest.php walks every route in this group.
    Route::get('/reservations', ListReservations::class)->name('reservations.index');
    Route::get('/reservations/{reservation}', ViewReservation::class)->whereNumber('reservation')->name('reservations.show');

    Route::get('/properties', ListProperties::class)->name('properties.index');
    Route::get('/properties/{property}', ViewProperty::class)->whereNumber('property')->name('properties.show');

    // Slice 4.1: owners edit; a caretaker gets 403 on every one of these (the pages above stay read-only for them).
    Route::get('/properties/create', NewProperty::class)->name('properties.create');
    Route::post('/properties', StoreProperty::class)->name('properties.store');
    Route::get('/properties/{property}/edit', EditProperty::class)->whereNumber('property')->name('properties.edit');
    Route::put('/properties/{property}', SaveProperty::class)->whereNumber('property')->name('properties.update');
    Route::post('/properties/{property}/archive', ArchivePropertyController::class)->whereNumber('property')->name('properties.archive');
    Route::delete('/properties/{property}/archive', UnarchivePropertyController::class)->whereNumber('property')->name('properties.unarchive');

    Route::get('/properties/{property}/rooms/create', NewRoom::class)->whereNumber('property')->name('properties.rooms.create');
    Route::post('/properties/{property}/rooms', StoreRoom::class)->whereNumber('property')->name('properties.rooms.store');
    Route::get('/rooms/{room}/edit', EditRoom::class)->whereNumber('room')->name('rooms.edit');
    Route::put('/rooms/{room}', SaveRoom::class)->whereNumber('room')->name('rooms.update');
    // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production): the archive-versus-reserve race proves overlap with it.
    Route::post('/rooms/{room}/archive', ArchiveRoomController::class)->whereNumber('room')->middleware(ExposeRequestTiming::class)->name('rooms.archive');
    Route::delete('/rooms/{room}/archive', UnarchiveRoomController::class)->whereNumber('room')->name('rooms.unarchive');

    Route::post('/rooms/{room}/spaces', StoreSpace::class)->whereNumber('room')->name('rooms.spaces.store');
    Route::patch('/spaces/{space}', RenameSpaceController::class)->whereNumber('space')->name('spaces.update');
    Route::post('/spaces/{space}/archive', ArchiveSpaceController::class)->whereNumber('space')->name('spaces.archive');
    Route::delete('/spaces/{space}/archive', UnarchiveSpaceController::class)->whereNumber('space')->name('spaces.unarchive');

    // Slice 4.2: photos. Owners only; a caretaker sees them on the property page. The DELETE stays last:
    // T6 calls these in order on one photo of its own, and the others need it to still exist.
    Route::post('/properties/{property}/photos', StorePropertyPhoto::class)->whereNumber('property')->name('properties.photos.store');
    Route::patch('/photos/{photo}', DescribePropertyPhotoController::class)->whereNumber('photo')->name('photos.update');
    Route::post('/photos/{photo}/move', MovePropertyPhotoController::class)->whereNumber('photo')->name('photos.move');
    Route::post('/photos/{photo}/retry', RetryPropertyPhotoController::class)->whereNumber('photo')->name('photos.retry');
    Route::delete('/photos/{photo}', DeletePropertyPhotoController::class)->whereNumber('photo')->name('photos.destroy');

    // Slice 4.3: publish and unpublish (owners).
    Route::post('/properties/{property}/publish', PublishPropertyController::class)->whereNumber('property')->name('properties.publish');
    Route::delete('/properties/{property}/publish', UnpublishPropertyController::class)->whereNumber('property')->name('properties.unpublish');

    Route::get('/members', ListMembers::class)->name('members.index');
    Route::post('/invitations', StoreInvitation::class)->name('invitations.store');
    // Slice 4.3 (owners). The resend stays before the DELETE: T6 calls them in order on one invitation, and a revoked one can't be sent again.
    Route::post('/invitations/{invitation}/resend', ResendInvitationController::class)->whereNumber('invitation')->name('invitations.resend');
    Route::delete('/invitations/{invitation}', RevokeInvitationController::class)->whereNumber('invitation')->name('invitations.destroy');
    // `landlord_members` has no isolation scope: LandlordMemberPolicy::remove makes another account's member a 404.
    Route::delete('/members/{member}', RemoveMemberController::class)->whereNumber('member')->middleware(ExposeRequestTiming::class)->name('members.destroy');

    // Slice 6.1: the landlord's moves in the reservation lifecycle (RESERVATIONS.md "Transition table"). Owner and
    // caretaker, except withdraw (owners). Registered last of the group, in this order, on purpose: T6 calls them in
    // order on its own proof_uploaded reservation (confirm, undo, decline, then the refund on the declined one; the
    // rest are the transition table's 422), after the archive routes, which need that reservation to still block.
    // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production): the 6.1 races prove overlap with it.
    Route::post('/reservations/{reservation}/confirm', ConfirmReservationController::class)->whereNumber('reservation')->middleware(ExposeRequestTiming::class)->name('reservations.confirm');
    Route::post('/reservations/{reservation}/undo', UndoConfirmationController::class)->whereNumber('reservation')->name('reservations.undo');
    Route::post('/reservations/{reservation}/decline', DeclineReservationController::class)->whereNumber('reservation')->middleware(ExposeRequestTiming::class)->name('reservations.decline');
    Route::put('/reservations/{reservation}/refund', RecordRefundController::class)->whereNumber('reservation')->name('reservations.refund');
    Route::post('/reservations/{reservation}/reject', RejectProofController::class)->whereNumber('reservation')->name('reservations.reject');
    Route::post('/reservations/{reservation}/withdraw', WithdrawReservationController::class)->whereNumber('reservation')->name('reservations.withdraw');
    Route::post('/reservations/{reservation}/moved-in', MarkMovedInController::class)->whereNumber('reservation')->name('reservations.moved-in');
    Route::post('/reservations/{reservation}/end', EndStayController::class)->whereNumber('reservation')->name('reservations.end');

    // Slice 6.2: viewing requests. Owner and caretaker alike. Registered last, in this order: T6 calls them in order
    // on its own requested viewing (accept, then decline = a changed answer, then done = refused before the time).
    Route::get('/viewings', ListViewings::class)->name('viewings.index');
    Route::get('/viewings/{viewing}', ViewViewing::class)->whereNumber('viewing')->name('viewings.show');
    Route::post('/viewings/{viewing}/accept', AcceptViewingController::class)->whereNumber('viewing')->name('viewings.accept');
    Route::post('/viewings/{viewing}/decline', DeclineViewingController::class)->whereNumber('viewing')->name('viewings.decline');
    Route::post('/viewings/{viewing}/done', MarkViewingDoneController::class)->whereNumber('viewing')->name('viewings.done');
});
