<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount('orders')
            ->when($request->string('search')->toString(), function ($query, $search) {
                $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('telegram_id', 'like', '%'.$search.'%'));
            })
            ->latest()
            ->paginate(25);

        return response()->json($users);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['orders' => fn ($query) => $query->with('payment')->latest()]));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_admin' => ['required', 'boolean']]);
        abort_if($request->user()->is($user) && ! $data['is_admin'], 422, 'You cannot remove your own administrator role.');
        $user->update($data);

        return response()->json($user->fresh()->loadCount('orders'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot delete your own account.');
        abort_if($user->orders()->exists(), 422, 'Users with order history cannot be deleted.');
        $user->delete();

        return response()->json(null, 204);
    }
}
