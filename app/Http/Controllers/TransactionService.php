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

            if ($feeRate == 0 ){ //Transactions with user selected fee 0 are direct transactions not registered in links 

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
    
                // Update receiver's balance, auxiliary, and link income
                $receiver->balance += $amount;
                $receiver->auxiliary += $fee;
                $receiver->trxCount += 1; // Increment receiver's trxCount
                $receiver->value = $this->calculateValue($receiver);
                $receiver->save();
    
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
            return 0;
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

    private function updateLink($senderId, $receiverId, $amount, $feeRate)
    {
        $existingLink = DB::table('links')->where([
            ['sender_id', '=', $senderId],
            ['receiver_id', '=', $receiverId]
        ])->first();

        if ($existingLink) {
            // Update link rate if it's not 0 -special case from distributions-
            $newRate = 0; //initialization of this variable ??
            if ($feeRate == 0){
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
$receiverId->trigger-=1; //trigger keeps track of incoming links
            }
        } else {
            // Insert new link
            DB::table('links')->insert([
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'amount' => $amount,
                'rate' => $feeRate
            ]);
$receiverId->trigger += 1; //trigger keeps track of incoming links
        }
    }

    private function clearPendingDistributions(): void
    {
$accounts = Account::where('trxCount', '=', DB::raw('trigger + 1'))->get(); //trigger condition,not sure if the +1 can be added there. 

        foreach ($accounts as $account) {
            Log::info('distributor ' . $account);
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
            if ($participant && $participant->balance < $participant->value) {
                $participants->push($participant);
                $totalPR += $participant->public_rate;
            }
        }

        // Also consider the account itself as a potential participant
        if ($account->balance < $account->value) {
            $participants->push($account);
            $totalPR += $account->public_rate;
        }

        // Track whether any distributions occurred
        $distributionOccurred = false;

        // Only proceed if totalPR is greater than zero
        if ($totalPR > 0) {
            // Calculate and distribute share for each participant
            foreach ($participants as $participant) {
                $share = $distributionAmount * ($participant->public_rate / $totalPR);
                if ($share > 0) {
                    $distributionOccurred = true;
                    $share = min($share, $participant->value - $participant->balance);
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

    private function createDistributionTransaction($account, $participant, $share)
    {
        Log::info('Creating distribution transaction from ' . $account->id . ' to ' . $participant->id);

        // Handle case where the participant is the same as the account
        if ($participant->id == $account->id) {
            $participant->balance += $share;
            $participant->auxiliary -= $share;
            $participant->save();
        } else {
            // Update participant's auxiliary and link obligation
            $participant->auxiliary += $share;
            $participant->trxCount += 1;
            $participant->save();

            $account->auxiliary -= $share;
            $account->save();

            $this->updateLink($participant->id, $account->id, -$share, 0); // rate 0 is a special case that doesn't change the link rate

            // Check and delete link if amount is zero or negative 
            $existingLink = DB::table('links')
            ->where('sender_id', $participant->id)
            ->where('receiver_id', $account->id)
            ->first();

            if ($existingLink && $existingLink->amount <= 0) {
                DB::table('links')
                ->where('sender_id', $participant->id)
                ->where('receiver_id', $account->id)
                ->delete();
            }
            
            // Update participant's public rate
            $participant->public_rate = $this->calculateNewPR($participant);
            $participant->save();
        }  
    }
}
