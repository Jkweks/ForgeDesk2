<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/data/inventory.php';

function assertAlmostEquals(float $expected, float $actual, float $tolerance = 0.0001): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(sprintf('Expected %.4f but received %.4f', $expected, $actual));
    }
}

// 1. No recommendation when available meets or exceeds the reorder point
$quantity = inventoryCalculateRecommendedOrderQuantity(30, 35.0);
assertAlmostEquals(0.0, $quantity);

// 2. Simple shortfall is calculated from reorder point minus available
$quantity = inventoryCalculateRecommendedOrderQuantity(30, -2.0);
assertAlmostEquals(32.0, $quantity);

// 3. Zero or negative reorder points only cover negative availability back to zero
$quantity = inventoryCalculateRecommendedOrderQuantity(-5, -10.0);
assertAlmostEquals(10.0, $quantity);

// 4. Shortfalls are rounded to three decimal places
$quantity = inventoryCalculateRecommendedOrderQuantity(5, 1.6665);
assertAlmostEquals(3.334, $quantity, 0.0005);

echo "All replenishment tests passed\n";
