<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function createTransaction($senderId, $receiverId, $amount, $feeRate)
    {
        return DB::transaction(function () use ($senderId, $receiverId, $amount, $feeRate) {
            $sender = Account::findOrFail($senderId);
            $receiver = Account::findOrFail($receiverId);

            $transaction = Transactions::create([
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'amount' => $amount,
                'fee_rate' => $feeRate,
                'is_distributed' => false
            ]);

            if ($feeRate == 0) { //Transactions with user selected fee 0 are direct transactions not registered in links

                //update sender balance
                $sender->balance -= ($amount);
                $sender->value = $this->calculateValue($sender);
                $sender->save();

                //update receiver balance
                $receiver->balance += $amount;
                $receiver->value = $this->calculateValue($receiver);
                $receiver->save();

            } else {

                // Update links before updating sender and receiver
                $this->updateLink($senderId, $receiverId, $amount, $feeRate);

                // Calculate fee
                $fee = $amount * ($feeRate / 100);

                // Update sender's balance and obligations
                $sender->balance -= ($amount + $fee);
                $sender->save();
                $sender->public_rate = $this->calculateNewPR($sender->refresh());
                $sender->value = $this->calculateValue($sender);
                $sender->save();

                // Update receiver's balance and trxCount
                $receiver->balance += $amount;
                //$receiver->auxiliary += 0.9*$fee;
                //$receiver->trxCount += 1; // Increment receiver's trxCount
                $receiver->save();

                $this->updateLink($receiverId, 1, ($fee * 1), $receiver->public_rate);

                $receiver->public_rate = $this->calculateNewPR($receiver);
                $receiver->value = $this->calculateValue($receiver);
                $receiver->save();

                $this->sendToAdmin($fee);

                $this->clearPendingDistributions();
            }
            return $transaction;
        });
    }

    private function calculateNewPR($user): float|int
    {
        $totalAmount = DB::table('links')->where('sender_id', $user->id)->sum('amount');
        $sumProd = DB::table('links')->where('sender_id', $user->id)->sum(DB::raw('amount * rate'));

        Log::info($totalAmount . ' Total sum of Lo');
        Log::info('Amount * Fee ' . $sumProd);

        if ($totalAmount == 0) {
            return $user->public_rate;
        } else {
            Log::info('PR ' . $sumProd / $totalAmount);
            return $sumProd / $totalAmount;
        }
    }

    private function calculateValue($user)
    {
        $linkObligation = DB::table('links')->where('sender_id', $user->id)->sum('amount');
        $linkIncome = DB::table('links')->where('receiver_id', $user->id)->sum('amount');
        return $user->balance + $user->auxiliary - $linkIncome + $linkObligation;
    }

    private function updateLink($senderId, $receiverId, $amount, $feeRate): void
    {
        $existingLink = DB::table('links')->where([
            ['sender_id', '=', $senderId],
            ['receiver_id', '=', $receiverId]
        ])->first();

        if ($existingLink) {
            // Update link rate if it's not 0 -special case from distributions-
            $newRate = 0; //initialization of this variable ??
            if ($feeRate == 0) {
                // Update existing link
                DB::table('links')->where([
                    ['sender_id', '=', $senderId],
                    ['receiver_id', '=', $receiverId]
                ])->update([
                    'amount' => DB::raw('amount + ' . $amount),
                    //without rate update
                ]);
            } else {
                $newRate = (($existingLink->amount * $existingLink->rate) + ($amount * $feeRate)) / ($existingLink->amount + $amount);
                // Update existing link
                DB::table('links')->where([
                    ['sender_id', '=', $senderId],
                    ['receiver_id', '=', $receiverId]
                ])->update([
                    'amount' => DB::raw('amount + ' . $amount),
                    'rate' => $newRate
                ]);
            }

            // Check for link deletion
            if ($existingLink->amount <= 0) {
                DB::table('links')->where([
                    ['sender_id', '=', $senderId],
                    ['receiver_id', '=', $receiverId]
                ])->delete();

                $receiver = Account::find($receiverId);
                $receiver->trigger -= 1; //keep track of incoming links
                $receiver->save();

            }
        } else {
            // Insert new link
            DB::table('links')->insert([
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'amount' => $amount,
                'rate' => $feeRate
            ]);

            $receiver = Account::find($receiverId);
            $receiver->trigger += 1; //keep track of incoming links
            $receiver->save();

        }
    }

    private function clearPendingDistributions(): void
    {
        $accounts = Account::select('*')
            ->whereRaw('`trxCount` >= `trigger` + 1')
            ->get(); // trigger condition

        foreach ($accounts as $account) {
            Log::info('distributor ' . $account->name);
            $this->Distribute($account);
        }
    }

    private function Distribute($account): void
    {
        Log::info('Distributing for account: ' . $account->id);

        $distributionAmount = $account->auxiliary;

        // Get all participants (senders to the account and the account itself)
        $links = DB::table('links')->where('receiver_id', $account->id)->get();

        $totalPR = 0;
        $participants = collect();

        foreach ($links as $link) {
            $participant = Account::find($link->sender_id);
            $totalPR += $participant->public_rate;
            Log::info($participant->public_rate);
            $participants->push($participant);
        }

        // Also consider the account itself as a potential participant
        if ($account->balance < $account->value) {
            $participants->push($account);
            $totalPR += $account->public_rate;
        }

        if ($totalPR == 0) {
            $totalPR = 10;
        }

        Log::info('Total PR '.$totalPR);

        // Track whether any distributions occurred

        // Only proceed if totalPR is greater than zero
        if ($totalPR > 0) {
            // Calculate and distribute share for each participant
            foreach ($participants as $participant) {
                $pr = 10;
                if ($participant->public_rate > 0) {
                    $pr = $participant->public_rate;
                }

                $share = $distributionAmount * ($pr / $totalPR);
                Log::info('Share for ' . $participant->name . ': ' . $share);
                if ($share > 0) {
                    /*$share = $share;*/
                    Log::info($share . ' goes to ' . $participant->name);
                    $this->createDistributionTransaction($account, $participant, $share);
                }
            }
        } else {
            Log::warning('Total PR is zero, distribution skipped for account: ' . $account->id);
        }

        // Reset the account's trxCount after distribution
        $account->trxCount = 0;
        $account->save();
    }

    private function createDistributionTransaction($account, $participant, $share): void
    {
        Log::info('Creating distribution transaction from ' . $account->id . ' to ' . $participant->id);

        // Handle case where the participant is the same as the account
        if ($participant->id == $account->id) {
            $participant->balance += $share;
            $participant->auxiliary -= $share;
        } else {
            // Update participant's auxiliary and link obligation
            $participant->auxiliary += $share;
            $participant->trxCount += 1;
            $participant->save();

            $account->auxiliary -= $share;
            $account->save();

            $this->updateLink($participant->id, $account->id, -$share, 0); // rate 0 is a special case that doesn't change the link rate

            // Update participant's public rate
            $participant->public_rate = $this->calculateNewPR($participant);
        }
        $participant->save();
    }

    private function sendToAdmin($amount): void
    {
        $account = Account::find(1);
        $account->balance += 0 * $amount;
        $account->auxiliary += 1 * $amount;
        $account->trxCount += 1;
        $account->value = $this->calculateValue($account);
        $account->save();
    }
}
