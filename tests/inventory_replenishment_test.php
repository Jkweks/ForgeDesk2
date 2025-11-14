<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/data/inventory.php';

function assertAlmostEquals(float $expected, float $actual, float $tolerance = 0.0001): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(sprintf('Expected %.4f but received %.4f', $expected, $actual));
    }
}

// 1. No shortfall when projected exceeds target
$quantity = inventoryCalculateRecommendedOrderQuantity(120.0, 4.0, 5, 20.0, 0.0, 0.0, 0.0);
assertAlmostEquals(0.0, $quantity);

// 2. Minimum order quantity respected when shortfall is lower
$quantity = inventoryCalculateRecommendedOrderQuantity(10.0, 2.0, 5, 5.0, 20.0, 0.0, 0.0);
assertAlmostEquals(20.0, $quantity);

// 3. Order multiples are rounded up
$quantity = inventoryCalculateRecommendedOrderQuantity(0.0, 3.0, 4, 5.0, 0.0, 10.0, 0.0);
assertAlmostEquals(20.0, $quantity);

// 4. Pack sizes round the recommendation to case quantities
$quantity = inventoryCalculateRecommendedOrderQuantity(0.0, 2.0, 5, 3.0, 0.0, 5.0, 12.0);
assertAlmostEquals(24.0, $quantity);

// 5. Safety stock drives orders when usage is unavailable
$quantity = inventoryCalculateRecommendedOrderQuantity(2.0, null, 5, 10.0, 0.0, 0.0, 0.0);
assertAlmostEquals(8.0, $quantity);

// 6. Results are rounded to three decimal places
$quantity = inventoryCalculateRecommendedOrderQuantity(0.0, 1.0, 3, 0.3333, 0.0, 0.0, 0.0);
assertAlmostEquals(3.333, $quantity, 0.0005);

echo "All replenishment tests passed\n";
