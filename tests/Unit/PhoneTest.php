<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_kenyan_mobiles_become_e164(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::toE164($input));
    }

    public static function numbers(): array
    {
        return [
            'local 07' => ['0712 345 678', '+254712345678'],
            'local 01' => ['0110-123-456', '+254110123456'],
            'no zero' => ['712345678', '+254712345678'],
            'international' => ['+254 712 345 678', '+254712345678'],
            'without plus' => ['254712345678', '+254712345678'],
            'landline' => ['020 1234567', null],
            'too short' => ['07123', null],
            'other country' => ['+256712345678', null],
            'empty' => [null, null],
        ];
    }
}
