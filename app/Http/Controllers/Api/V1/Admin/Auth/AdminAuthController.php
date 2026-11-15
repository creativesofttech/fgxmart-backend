<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(AdminLoginRequest $request)
    {
        $data = $this->authService->adminLogin($request->email, $request->password);

        return response()->json([
            'message' => 'Admin Login Successful',
            'data' => $data,
        ]);
    }

    public function user(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'roles' => $admin->roles->pluck('slug')->values(),
                'permissions' => $admin->permissions()->pluck('slug')->values(),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
