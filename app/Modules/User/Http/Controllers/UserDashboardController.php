<?php

namespace App\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserDashboardController extends Controller
{
    /**
     * Личный кабинет пользователя
     */
    public function dashboard()
    {
        $user = Auth::user();
        
        // История заказов
        $bookings = DB::table('bookings')
            ->join('events', 'bookings.event_id', '=', 'events.id')
            ->leftJoin('venues', 'events.venue_id', '=', 'venues.id')
            ->where('bookings.user_id', $user->id)
            ->select(
                'bookings.*',
                'events.name as event_name',
                'events.start_at',
                'venues.name as venue_name'
            )
            ->orderBy('bookings.created_at', 'desc')
            ->paginate(10);

        // Избранные события
        $favorites = DB::table('favorites')
            ->join('events', 'favorites.event_id', '=', 'events.id')
            ->leftJoin('venues', 'events.venue_id', '=', 'venues.id')
            ->where('favorites.user_id', $user->id)
            ->select('events.*', 'venues.name as venue_name')
            ->orderBy('favorites.created_at', 'desc')
            ->get();

        return view('user::dashboard', compact('bookings', 'favorites'));
    }

    /**
     * Детали заказа
     */
    public function orderDetails($orderId)
    {
        $booking = DB::table('bookings')
            ->join('events', 'bookings.event_id', '=', 'events.id')
            ->leftJoin('venues', 'events.venue_id', '=', 'venues.id')
            ->where('bookings.id', $orderId)
            ->where('bookings.user_id', Auth::id())
            ->select(
                'bookings.*',
                'events.name as event_name',
                'events.description as event_description',
                'events.start_at',
                'events.end_at',
                'venues.name as venue_name',
                'venues.address as venue_address'
            )
            ->first();

        if (!$booking) {
            abort(404, 'Заказ не найден');
        }

        // Места в заказе
        $seats = DB::table('booking_seats')
            ->join('seats', 'booking_seats.seat_id', '=', 'seats.id')
            ->where('booking_seats.booking_id', $orderId)
            ->select('seats.row', 'seats.number', 'seats.type', 'booking_seats.price')
            ->get();

        return view('user::order-details', compact('booking', 'seats'));
    }

    /**
     * Профиль пользователя
     */
    public function profile()
    {
        return view('user::profile');
    }

    /**
     * Обновление профиля
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'subscribed_to_newsletter' => 'boolean',
        ]);

        $user->update($validated);

        return redirect()->back()->with('success', 'Профиль обновлен');
    }

    /**
     * Смена пароля
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = Auth::user();

        if (!\Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Неверный текущий пароль']);
        }

        $user->password = \Hash::make($validated['password']);
        $user->save();

        return back()->with('success', 'Пароль успешно изменен');
    }
}
