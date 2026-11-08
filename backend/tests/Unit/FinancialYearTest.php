<?php

namespace Tests\Unit;

use App\Support\FinancialYear;
use Tests\TestCase;

class FinancialYearTest extends TestCase
{
    public function test_label_switches_on_first_april(): void
    {
        $this->assertSame('25-26', FinancialYear::label('2026-03-31'));
        $this->assertSame('26-27', FinancialYear::label('2026-04-01'));
        $this->assertSame('99-00', FinancialYear::label('2099-12-01'));
    }

    public function test_start_and_end(): void
    {
        $this->assertSame('2026-04-01', FinancialYear::start('2026-11-08')->toDateString());
        $this->assertSame('2027-03-31', FinancialYear::end('2026-11-08')->toDateString());
    }
}
