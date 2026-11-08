<?php

namespace App\Http\Controllers\Kaarigar;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** OTP login/logout live in Auth\SessionController (one login for everyone). */
class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user('kaarigar')->toAuthPayload()]);
    }
}
