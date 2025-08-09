<?php

namespace App\Http\Controllers;

use App\Helper;
use Ramsey\Uuid\Uuid;
use App\Models\AudioCall;
use Illuminate\Http\Request;
use App\Enums\AudioCallStatus;

final class AudioCallController extends Controller
{
    use Traits\Functions;

    public function store(Request $request)
    {
        $user = auth()->user();

        $audioCall = AudioCall::create([
            'seller_id' => $user->id,
            'buyer_id' => $request->user,
            'price' => $user->price_audio_call,
            'status' => AudioCallStatus::CALLING,
            'minutes' => $user->audio_call_duration,
            'token' => Uuid::uuid4()->toString()
        ]);

        return response()->json([
            'status' => true,
            'buyer' => $audioCall->buyer_id,
            'audioCallId' => $audioCall->id,
        ]);
    }

    public function accept($id)
    {
        $audioCall = AudioCall::with(['seller:id'])
            ->whereId($id)
            ->whereStatus(AudioCallStatus::CALLING)
            ->first();

        if (!$audioCall) {
            return response()->json([
                'success' => false,
                'message' => __('general.audio_call_not_found')
            ]);
        }

        if (auth()->user()->wallet < Helper::amountGross($audioCall->price)) {

            $audioCall->update([
                'status' => AudioCallStatus::REJECTED
            ]);

            return response()->json([
                'success' => false,
                'message' => __('general.not_enough_funds')
            ]);
        }

        if ($audioCall->paid === 0) {
            $this->insertTransaction($audioCall->seller->id, $audioCall->price);
            $audioCall->buyer->decrement('wallet', Helper::amountGross($audioCall->price));

            $audioCall->update([
                'status' => AudioCallStatus::ACCEPTED,
                'paid' => 1
            ]);

            return response()->json([
                'status' => true,
                'seller' => $audioCall->seller->id
            ]);
        }
    }

    public function reject($id)
    {
        $audioCall = AudioCall::find($id);

        if ($audioCall) {
            $audioCall->update([
                'status' => AudioCallStatus::REJECTED
            ]);
        }

        return response()->json([
            'status' => true
        ]);
    }

    public function cancel($id)
    {
        $audioCall = AudioCall::whereId($id)
            ->where('buyer_id', request()->user)
            ->orWhere('id', $id)
            ->whereSellerId(auth()->id())
            ->first();

        if ($audioCall && $audioCall->status == AudioCallStatus::CALLING) {
            $audioCall->update([
                'status' => AudioCallStatus::CANCELED
            ]);
        }

        return response()->json([
            'status' => true
        ]);
    }

    public function delete($id)
    {
        AudioCall::whereId($id)->delete();

        return response()->json([
            'status' => true
        ]);
    }

    public function audioCallUrl($token)
    {
        $audioCall = AudioCall::with([
            'seller:id,name,avatar,username',
            'buyer:id,name,avatar',
        ])->whereToken($token)
            ->whereStatus('accepted')
            ->where('seller_id', auth()->id())
            ->whereNull('ended_at')
            ->orWhere('token', $token)
            ->whereStatus('accepted')
            ->where('buyer_id', auth()->id())
            ->whereNull('ended_at')
            ->firstOrFail();

        if (is_null($audioCall->started_at) && $audioCall->seller_id == auth()->id()) {
            $audioCall->update([
                'started_at' => now()
            ]);
        }

        if (is_null($audioCall->joined_at) && $audioCall->buyer_id == auth()->id()) {
            $audioCall->update([
                'joined_at' => now()
            ]);
        }

        $avatarCurrentUser = auth()->id() == $audioCall->seller_id ? $audioCall->buyer->avatar : $audioCall->seller->avatar;

        return view('users.audio-call')->with([
            'audioCall' => $audioCall,
            'avatarCurrentUser' => $avatarCurrentUser
        ]);
    }

    public function audioCallFinish(AudioCall $audioCall)
    {
        abort_if(
            $audioCall->seller->id != auth()->id()
                && $audioCall->buyer_id != auth()->id(),
            404
        );

        $audioCall->update([
            'ended_at' => now()
        ]);

        return redirect('/');
    }

    protected function insertTransaction($seller, $price)
    {
        // Admin and user earnings calculation
        $earnings = $this->earningsAdminUser(auth()->user()->custom_fee, $price, null, null);

        // Insert Transaction
        $this->transaction(
            'audiocall_' . str_random(25),
            auth()->id(),
            0,
            $seller,
            $price,
            $earnings['user'],
            $earnings['admin'],
            'Wallet',
            'audio_calls',
            $earnings['percentageApplied'],
            auth()->user()->taxesPayable()
        );
    }
}
