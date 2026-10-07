<?php

use App\Support\Commission\TierCalculator;
use App\Support\Season\IndianSeason;

it('pays marginal slabs only on the slice above each boundary', function () {
    $tiers = [
        ['min_amount' => 0, 'max_amount' => 50000, 'percent' => 1],
        ['min_amount' => 50000, 'max_amount' => null, 'percent' => 2],
    ];

    expect(TierCalculator::amount(0, 50000, $tiers, 'marginal'))->toBe(500.0);
    expect(TierCalculator::amount(0, 60000, $tiers, 'marginal'))->toBe(700.0);
    expect(TierCalculator::amount(50000, 60000, $tiers, 'marginal'))->toBe(200.0);
});

it('pays a flat slab on the whole total once the boundary is crossed', function () {
    $tiers = [
        ['min_amount' => 0, 'max_amount' => 50000, 'percent' => 1],
        ['min_amount' => 50000, 'max_amount' => null, 'percent' => 2],
    ];

    expect(TierCalculator::amount(0, 50000, $tiers, 'flat'))->toBe(500.0);
    expect(TierCalculator::amount(0, 60000, $tiers, 'flat'))->toBe(1200.0);
    expect(TierCalculator::amount(40000, 60000, $tiers, 'flat'))->toBe(800.0);
});

it('names indian nursery seasons and keeps winter as one year', function () {
    expect(IndianSeason::forDate('2026-04-15'))->toBe('summer');
    expect(IndianSeason::forDate('2026-07-01'))->toBe('monsoon');
    expect(IndianSeason::forDate('2026-11-02'))->toBe('winter');
    expect(IndianSeason::forDate('2026-01-20'))->toBe('winter');
    expect(IndianSeason::isStartWindow('2026-06-02'))->toBeTrue();
    expect(IndianSeason::isStartWindow('2026-06-10'))->toBeFalse();
    expect(IndianSeason::yearKey('2026-01-15'))->toBe(2025);
    expect(IndianSeason::yearKey('2026-10-01'))->toBe(2026);
});
