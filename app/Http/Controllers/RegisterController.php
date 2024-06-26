<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function register(Request $request): JsonResponse
    {
        $validatedData = Validator::make($request->all(), [
            'name' => 'required|string',
            'balance' => 'required|numeric',/*
            'link_obligation' => 'sometimes|numeric',
            'link_income' => 'sometimes|numeric',
            'public_rate' => 'sometimes|numeric|min:0|max:100',
            'auxiliary' => 'sometimes|numeric',*/
        ])->validate();

        $user = Account::create([
            'name' => $request->name,
            'balance' => $request->balance ?? 100, // default initial balance
            'link_obligation' =>  0,
            'link_income' =>  0,
            'value' => $request->balance ?? 100, // value is initially equal to balance
            'public_rate' =>  0, // default PR
            'auxiliary' => 0,
            'trigger' => $request->trigger
        ]);

        return response()->json(['user' => $user], 201);
    }

    public function list(): JsonResponse
    {
        $users = Account::all();

        return response()->json($users, 200);
    }

    public function reset(): JsonResponse
    {
        $account = Account::query();

        $account->update(['balance' => 100, 'link_obligation' => 0, 'link_income' => 0, 'value' => 100, 'public_rate' => 0, 'auxiliary' => 0, 'trigger' => 3, 'trxCount' => 0]);
        $trx = Transactions::query();
        $trx->delete();

        return response()->json(['message' => 'All users have been deleted'], 200);
    }

    public function find($id): JsonResponse
    {
        $user = Account::find($id);
        $transactions = Transactions::where('sender_id', $id)->count();
        $data = [
            'user' => $user,
            'transactions' => $transactions
        ];

        return response()->json($data, 200);
    }
}
