<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Room screen preview
|--------------------------------------------------------------------------
| Local dev/demo only — the actual client is Flutter. This just gives the seat + WebRTC
| voice-chat flow (Api\RoomController, room.{uuid} presence channel) something to be
| driven from in a browser, using DemoUsersSeeder/DemoRoomsSeeder fixtures.
*/

Route::get('/rooms/preview', function () {
    $room = Room::live()->first() ?? Room::first();

    if ($room !== null) {
        $url = route('rooms.preview', $room->uuid);
        $query = request()->getQueryString();

        return redirect()->to($query ? "{$url}?{$query}" : $url);
    }

    return view('rooms.show', ['room' => null, 'demoUsers' => collect()]);
})->name('rooms.preview.index');

Route::get('/rooms/{room:uuid}/preview', function (Room $room) {
    $room->load(['owner.profile:id,user_id,display_name,avatar_url', 'seats.user.profile:id,user_id,display_name,avatar_url']);

    $demoUsers = app()->environment('local')
        ? User::with('profile:id,user_id,display_name')->where('status', User::STATUS_ACTIVE)->limit(8)->get()
        : collect();

    return view('rooms.show', ['room' => $room, 'demoUsers' => $demoUsers]);
})->name('rooms.preview');
