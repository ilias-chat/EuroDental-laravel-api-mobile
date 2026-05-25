<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $emailInput = Str::lower(trim((string) $request->input('email')));

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$emailInput])
            ->first();

        if (! $user) {
            $clientOnly = Client::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$emailInput])
                ->exists();

            if ($clientOnly) {
                return response()->json([
                    'message' => 'Ce compte est un compte client. Utilisez le même e-mail et mot de passe que pour la connexion admin / équipe (table utilisateurs), pas le compte webshop.',
                ], 403);
            }

            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        if ($user->is_blocked) {
            return response()->json(['message' => 'Votre compte a été bloqué.'], 403);
        }

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $request->password,
        ])) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        $token = $user->createToken('tasks-app')->plainTextToken;

        $user->load(['profile', 'image']);

        $imageUrl = storage_public_url($user->image?->image_name);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->first_name.' '.$user->last_name,
                'email' => $user->email,
                'image' => $imageUrl,
                'profile' => $user->profile?->profile_name,
                'permissions' => $user->getPermissions(),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load(['profile', 'image']);

        $imageUrl = storage_public_url($user->image?->image_name);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->first_name.' '.$user->last_name,
                'email' => $user->email,
                'image' => $imageUrl,
                'profile' => $user->profile?->profile_name,
                'permissions' => $user->getPermissions(),
            ],
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);
    
        $user = User::where('email', $request->email)->first();
    
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }
    
        $newPassword = Str::random(10);
        $user->password = bcrypt($newPassword);
        $user->requires_password_change = true;
        $user->save();
    
        try {
            Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($user, $newPassword));
    
            return response()->json([
                'success' => true,
                'message' => 'Un nouveau mot de passe a été envoyé à votre adresse e-mail.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = bcrypt($password);
                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset'])
            : response()->json(['message' => 'Failed to reset password'], 500);
    }
}
