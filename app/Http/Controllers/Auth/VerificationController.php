<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
class VerificationController extends Controller
{
    public function verify(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $user->markEmailAsVerified();
        event(new Verified($user));
        $user->status = 'active';
        $user->save();

        // return redirect()->to('http://192.168.0.133:3000/login')
        // ->with(['status' => 'success', 'message' => 'Email successfully verified.']);
        return redirect()->to('https://web.smsforyou.biz/login')
        ->with(['status' => 'success', 'message' => 'Email successfully verified.']);
    }
}
