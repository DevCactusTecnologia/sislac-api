<?php

namespace App\Http\Controllers\Auth;

use App\Platform\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentClinicalUserController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $laboratory = $user->laboratory;

        return response()->json([
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'laboratory' => $laboratory === null ? null : [
                'id' => $laboratory->getKey(),
                'name' => $laboratory->name,
                'code' => $laboratory->code,
                'status' => $laboratory->status,
            ],
        ]);
    }
}
