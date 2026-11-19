<?php

use App\Pixelforge\Pricing;
use App\Support\Nano;

it('converts between decimal strings and nanodollars without losing a digit', function () {
    expect(Nano::fromDecimal('0.046125000'))->toBe(46_125_000)
        ->and(Nano::fromDecimal('10'))->toBe(10_000_000_000)
        ->and(Nano::toDecimal(46_125_000))->toBe('0.046125000')
        ->and(Nano::toDecimal(-5))->toBe('-0.000000005')
        ->and(Nano::perTokenFromRate('12.50'))->toBe(12_500);
});

it('refuses amounts it cannot represent exactly', function (string $amount) {
    Nano::fromDecimal($amount);
})->throws(InvalidArgumentException::class)->with(['0.0000000001', '-1', '1e3', '']);

it('marks up by exactly 25%, rounding up only when that is not exact', function () {
    expect(Nano::withMarkup(373_875_000))->toBe(467_343_750)
        ->and(Nano::withMarkup(1))->toBe(2)
        ->and(Nano::withMarkup(4))->toBe(5);
});

it('prices the docs worked example: the most a generation can cost', function () {
    $pricing = new Pricing(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true));

    // high, 1536x1024, 400-character prompt, 2 reference images → Pixelforge $0.373875, user $0.46734375
    expect($pricing->maxUserPriceNano(str_repeat('a', 400), '1536x1024', 'high', 2))->toBe(467_343_750);
});

it('counts prompt characters, not bytes', function () {
    $pricing = new Pricing(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true));

    expect($pricing->maxUserPriceNano(str_repeat('é', 400), '1024x1024', 'low', 0))
        ->toBe($pricing->maxUserPriceNano(str_repeat('a', 400), '1024x1024', 'low', 0));
});

it('computes exact costs that match the docs usage report', function () {
    $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    // GET /v1/usage example day: 41 text, 0 image, 3300 output, 2950 reasoning tokens = "0.169080000"
    $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

    expect(Nano::toDecimal(Pricing::costNano($usage, $rates)))->toBe('0.169080000');
});
