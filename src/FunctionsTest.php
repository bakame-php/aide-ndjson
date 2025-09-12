<?php

declare(strict_types=1);

use Bakame\Aide\NdJson\Format;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

use function Bakame\Aide\NdJson\ndjson_decode;
use function Bakame\Aide\NdJson\ndjson_encode;

#[CoversFunction('Bakame\Aide\NdJson\ndjson_decode')]
#[CoversFunction('Bakame\Aide\NdJson\ndjson_encode')]
final class FunctionsTest extends TestCase
{
    public function test_function_helpers_convert_or_encode_ndjson_data(): void
    {
        $records = [
            ['Name' => 'Alice', 'Score' => 42],
            ['Name' => 'Bob', 'Score' => 27],
        ];

        self::assertEquals(
            $records,
            iterator_to_array(
                ndjson_decode(
                    ndjson: ndjson_encode(value: $records)
                )
            )
        );

        self::assertNotEquals(
            $records,
            iterator_to_array(
                ndjson_decode(
                    ndjson: ndjson_encode(
                        value: $records,
                        format: Format::List
                    ),
                    format: Format::List
                )
            )
        );

        self::assertEquals(
            $records,
            iterator_to_array(
                ndjson_decode(
                    ndjson: ndjson_encode(
                        value: $records,
                        format: Format::ListWithHeader
                    ),
                    format: Format::ListWithHeader
                ),
                false
            )
        );

        self::assertNotEquals(
            $records,
            iterator_to_array(
                ndjson_decode(
                    ndjson: ndjson_encode(
                        value: $records,
                        format: Format::ListWithHeader
                    ),
                    format: Format::ListWithHeader
                ),
            )
        );


    }
}
