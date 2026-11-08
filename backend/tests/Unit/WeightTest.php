<?php

namespace Tests\Unit;

use App\Support\Metal\Weight;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeightTest extends TestCase
{
    #[DataProvider('fineCases')]
    public function test_fine_weight(string $gross, string $purity, string $expected): void
    {
        $this->assertSame($expected, Weight::fine($gross, $purity));
    }

    public static function fineCases(): array
    {
        return [
            '22K 100 g' => ['100', '91.6', '91.600'],
            '18K 10 g' => ['10.000', '75.000', '7.500'],
            '24K 999' => ['50.000', '99.900', '49.950'],
            'rounds half up' => ['10.005', '91.6', '9.165'],       // 9.16458 → 9.165
            'rounds down' => ['1.234', '91.6', '1.130'],           // 1.130344 → 1.130
            'milligram' => ['0.001', '91.6', '0.001'],             // 0.000916 → 0.001
            'zero' => ['0', '91.6', '0.000'],
            'large lot' => ['12345.678', '91.600', '11308.641'],    // 11308.641048
        ];
    }

    public function test_round_half_away_from_zero(): void
    {
        $this->assertSame('1.001', Weight::round('1.0005'));
        $this->assertSame('1.000', Weight::round('1.0004999'));
        $this->assertSame('-1.001', Weight::round('-1.0005'));
        $this->assertSame('0.000', Weight::round('-0.0004'));
    }

    public function test_net_gold_excludes_stones_and_findings(): void
    {
        $this->assertSame('8.250', Weight::net('10.000', '1.500', '0.250'));
    }

    public function test_add_and_sub_are_exact(): void
    {
        // Floats would give 0.30000000000000004 here.
        $this->assertSame('0.300', Weight::add('0.1', '0.2'));
        $this->assertSame('1.600', Weight::sub('100.000', '98.400'));
    }

    public function test_reel_example_losses(): void
    {
        // Ramesh issued 100 g, returns 98.4 g → 1.6 g loss = 1.6 %.
        $this->assertSame('1.600', Weight::percentOf(Weight::sub('100', '98.4'), '100'));
        // Imran issued 80 g, returns 79.1 g → 0.9 g = 1.125 %.
        $this->assertSame('1.125', Weight::percentOf(Weight::sub('80', '79.1'), '80'));
    }

    public function test_percent_of_zero_is_zero(): void
    {
        $this->assertSame('0.000', Weight::percentOf('1', '0'));
    }

    public function test_compare(): void
    {
        $this->assertSame(0, Weight::compare('1.0000', '1.000'));
        $this->assertSame(1, Weight::compare('1.001', '1.000'));
    }

    public function test_rejects_non_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Weight::fine('1e3', '91.6');
    }
}
