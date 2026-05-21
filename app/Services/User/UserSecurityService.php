<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserSecurityService
{
    /**
     * @return array{status: bool, message: string, email?: string, http_status: int}
     */
    public function updateSecurity(Request $request): array
    {
        try {
            $user = User::where('id', $request->id)->firstOrFail();
            $user->ip_auth = $request->ip_auth == true ? 'true' : 'false';
            $user->two_fector_auth = $request->two_fector_auth == true ? 'true' : 'false';
            $user->ip_addresses = $request->ip_addresses;
            $user->save();

            return [
                'status' => true,
                'message' => 'Security Method Updated Successfully',
                'email' => $user->email,
                'http_status' => 200,
            ];
        } catch (QueryException $e) {
            return [
                'status' => false,
                'message' => 'Query Exception: '.$e->getMessage(),
                'http_status' => 500,
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'An error occurred while processing the request.'.$e->getMessage(),
                'http_status' => 500,
            ];
        }
    }

    /**
     * @return array{message?: string, error?: string, http_status: int}
     */
    public function checkPassword(Request $request): array
    {
        $user = User::find($request->id);

        if (! $user) {
            return ['error' => 'User not found', 'http_status' => 404];
        }

        if (Hash::check((string) $request->password, $user->password)) {
            return [
                'message' => 'Password is correct, you are verified successfully',
                'http_status' => 200,
            ];
        }

        return ['error' => 'Oops! Password is incorrect', 'http_status' => 401];
    }

    /**
     * @return array{message: string, http_status: int}
     */
    public function updateCreditLimit(Request $request): array
    {
        $user = User::firstOrFail($request->id);
        $user->low_credit_limit = $request->amount;
        $user->save();

        return ['message' => 'Limit Updated Successfully', 'http_status' => 200];
    }
}
