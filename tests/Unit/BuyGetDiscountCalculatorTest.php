<?php

namespace Tests\Unit;

use App\Services\CheckoutService;
use App\Services\ScratchCardService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class BuyGetDiscountCalculatorTest extends TestCase
{
    private CheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutService = new CheckoutService(
            Mockery::mock(ScratchCardService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_buy_two_get_one_discounts_cheapest_unit(): void
    {
        $items = new Collection([
            ['price' => 500, 'quantity' => 1],
            ['price' => 300, 'quantity' => 1],
            ['price' => 200, 'quantity' => 1],
        ]);

        $discount = $this->checkoutService->calculateBuyTwoGetOneDiscount($items, 2, 1);

        $this->assertSame(200.0, $discount);
    }

    public function test_buy_three_get_one_discounts_cheapest_unit(): void
    {
        $items = new Collection([
            ['price' => 500, 'quantity' => 1],
            ['price' => 400, 'quantity' => 1],
            ['price' => 300, 'quantity' => 1],
            ['price' => 200, 'quantity' => 1],
        ]);

        $discount = $this->checkoutService->calculateBuyTwoGetOneDiscount($items, 3, 1);

        $this->assertSame(200.0, $discount);
    }

    public function test_buy_two_get_one_with_six_units_gives_two_free(): void
    {
        $items = new Collection([
            ['price' => 100, 'quantity' => 6],
        ]);

        $discount = $this->checkoutService->calculateBuyTwoGetOneDiscount($items, 2, 1);

        $this->assertSame(200.0, $discount);
    }

    public function test_insufficient_units_returns_zero(): void
    {
        $items = new Collection([
            ['price' => 500, 'quantity' => 2],
        ]);

        $discount = $this->checkoutService->calculateBuyTwoGetOneDiscount($items, 2, 1);

        $this->assertSame(0.0, $discount);
    }
}
