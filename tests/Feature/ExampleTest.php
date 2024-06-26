<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_the_application_returns_a_successful_response_for_register(): void
    {
        $response = $this->postJson('/register', ['name' => 'Alice', 'balance' => 100, 'auxiliary_balance' => 0, 'meta_balance' => 0]);

        $response->assertStatus(201);
    }

    public function test_the_application_returns_a_successful_response_for_transfer(): void
    {
        $response = $this->postJson('/transfer', ['from_account_id' => 1, 'to_account_id' => 2, 'amount' => 100, 'fee' => 0]);

        $response->assertStatus(201);
    }

    public function test_the_application_returns_a_successful_response_for_distribute(): void
    {
        $response = $this->postJson('/distribute', ['account_id' => 1]);

        $response->assertStatus(201);
    }

    use RefreshDatabase;

    /** @test */
    public function it_creates_a_transaction()
    {
        $fromAccount = Account::where('name', 'Alice')->first();
        $toAccount = Account::where('name', 'Bob')->first();

        $response = $this->postJson('/api/transactions', [
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => 10
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('transactions', ['from_account_id' => $fromAccount->id, 'to_account_id' => $toAccount->id, 'amount' => 10]);
    }

    /** @test */
    public function it_adjusts_metabalance_after_transaction()
    {
        $fromAccount = Account::where('name', 'Alice')->first();
        $toAccount = Account::where('name', 'Bob')->first();

        $this->postJson('/api/transactions', [
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => 10
        ]);

        $toAccount->refresh();
        $this->assertEquals(1, $toAccount->auxiliary_balance); // 10% fee of 10
    }

    use RefreshDatabase;

    /** @test */
    public function it_adjusts_all_metabalances()
    {
        $account = Account::where('name', 'Alice')->first();

        $response = $this->postJson('/api/adjust-metabalances');

        $response->assertStatus(200);
        $account->refresh();
        $this->assertEquals(0, $account->auxiliary_balance);
        $this->assertEquals(10, $account->metabalance);
    }
}
