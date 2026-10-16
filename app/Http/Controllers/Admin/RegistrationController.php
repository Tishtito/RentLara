<?php

namespace App\Http\Controllers\Admin;

use App\Events\Commands\LandlordRegistrationSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RegisterLandlordRequest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Auth', weight: 0)]
class RegistrationController extends Controller
{
    /**
     * Sign up as a landlord.
     *
     * Creates a new landlord account (named after `business_name`, or the person's name) and its
     * landlord login. Nobody is logged in yet: log in afterwards with the same email and password
     * (`POST /api/admin/auth/login` in the browser, `POST /api/admin/auth/token` in the apps).
     * Tenants can't sign up; their landlord invites them. Limited to 3 attempts per minute.
     *
     * @unauthenticated
     */
    public function store(RegisterLandlordRequest $request): JsonResponse
    {
        LandlordRegistrationSubmitted::dispatch($request->toData());

        return response()->json(['message' => 'Account created. Log in to continue.'], 202);
    }
}
