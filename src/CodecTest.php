<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use ArrayIterator;
use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplTempFileObject;
use ValueError;

use function array_keys;
use function explode;
use function file;
use function iterator_to_array;
use function json_decode;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tmpfile;
use function trim;
use function unlink;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

#[CoversClass(Codec::class)]
#[CoversClass(Decoder::class)]
#[CoversClass(Encoder::class)]
#[CoversClass(Stream::class)]
#[CoversClass(MapIterator::class)]
#[CoversClass(DecodingNdJsonFailed::class)]
#[CoversClass(EncodingNdJsonFailed::class)]
#[CoversFunction('Bakame\Aide\NdJson\ndjson_read')]
#[CoversFunction('Bakame\Aide\NdJson\ndjson_write')]
final class CodecTest extends TestCase
{
    public function test_chunk_size_and_flags_are_validated(): void
    {
        $this->expectException(InvalidNdJsonArgument::class);

        new Codec(chunkSize: 0); /* @phpstan-ignore-line */
    }

    public function test_it_can_be_used_with_fluent_api(): void
    {
        $codec = new Codec();

        $newCodec = $codec
            ->chunkSize(10)
            ->addFlags(JSON_PRETTY_PRINT)
        ;

        self::assertNotSame($codec, $newCodec);
        self::assertSame(10, $newCodec->chunkSize);
        self::assertTrue($newCodec->usePrettyPrint());
    }

    public function test_it_can_encode_with_formatter(): void
    {
        $json = (new Codec())
            ->formatter(fn ($row) => ['value' => $row])
            ->encode([1, 2]);
        $lines = explode("\n", trim($json));

        self::assertCount(2, $lines);
        self::assertSame(['value' => 1], json_decode($lines[0], true));
    }

    public function test_it_can_decode_with_mapper(): void
    {
        $data = [
            json_encode(['n' => 5]),
            json_encode(['n' => 10]),
        ];
        $ndjson = implode("\n", $data);

        $decoded = iterator_to_array(
            (new Codec())
                ->mapper(fn (array $row): int => $row['n']) /* @phpstan-ignore-line */
                ->decode($ndjson)
        );

        self::assertSame([5, 10], $decoded);
    }

    public function test_it_can_download_a_file(): void
    {
        $rows = [
            ['Name' => 'Alice', 'Score' => 42],
            ['Name' => 'Bob', 'Score' => 27],
        ];
        $tabular = new ArrayIterator($rows);

        $json = ndjson_encode($tabular);
        $lines = explode("\n", trim($json));
        self::assertCount(2, $lines);

        $decoded = ndjson_decode($json);
        self::assertSame(['Alice', 'Bob'], array_column(iterator_to_array($decoded), 'Name'));
    }

    public function test_it_can_encode_to_string(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        self::assertSame($expected, (new Encoder())->encode($data));
    }

    public function test_it_silently_exclude_json_pretty_print_when_encoding(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        self::assertSame($expected, (new Encoder(flags: JSON_PRETTY_PRINT))->encode($data));
    }

    public function test_it_can_decode_to_string(): void
    {
        $data = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";
        $expected = [['key' => 'value'], ['key2' => 'value2']];

        self::assertSame($expected, iterator_to_array(ndjson_decode($data)));
    }

    public function test_it_can_encode_to_a_stream(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        /** @var resource $file */
        $file = tmpfile();

        ndjson_write($data, $file);
        rewind($file);
        self::assertSame($expected, stream_get_contents($file));
    }

    public function test_it_can_decode_from_a_stream(): void
    {
        $data = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";
        $expected = [['key' => 'value'], ['key2' => 'value2']];
        $file = new SplTempFileObject();
        $file->fwrite($data);

        self::assertSame($expected, iterator_to_array(ndjson_read($file)));
    }

    public function test_it_can_stream_the_encoding(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = [
            '{"key":"value"}'."\n".'{"key2":"value2"}'."\n",
        ];

        self::assertSame($expected, iterator_to_array((new Encoder(chunkSize: 500))->convert($data)));
    }

    public function test_it_can_return_a_tabular_data(): void
    {
        $ldjsonHeader = <<<LDJSON
["Name","Score","Completed"]
["Gilbert",24,true]
["Alexa",29,true]
LDJSON;
        $tabular = (new Codec())->decode($ldjsonHeader, headerOrOffset: 0);
        /** @var array<array<mixed>> $data */
        $data = iterator_to_array($tabular);

        self::assertSame(['Name', 'Score', 'Completed'], array_keys($data[1]));
        self::assertCount(3, $data);
    }

    public function test_it_can_return_a_tabular_data_from_a_list_of_object(): void
    {
        $ldjsonHeader = <<<LDJSON
{"name":"Gilbert","score":24,"completed":true}
{"name":"Alexa","score":29,"completed":true}
LDJSON;
        $tabular = (new Codec())->decode($ldjsonHeader, headerOrOffset: 0);
        /** @var array<array<mixed>> $data */
        $data = iterator_to_array($tabular);

        self::assertSame(['name', 'score', 'completed'], array_keys($data[0]));
        self::assertCount(2, $data);
    }

    public function test_it_can_read_nothing_from_an_empty_string(): void
    {
        $tabular = (new Codec())->decode('');
        $data = iterator_to_array($tabular);

        self::assertCount(0, $data);
    }

    public function test_it_fails_to_instantiate_an_empty_file(): void
    {
        $this->expectException(ValueError::class);

        (new Codec())->read(''); /* @phpstan-ignore-line */
    }

    private function setTabularData(): Iterator
    {
        $records = [
            ['Name' => 'Alice', 'Score' => 42],
            ['Name' => 'Bob', 'Score' => 27],
        ];

        return new ArrayIterator($records);
    }

    public function test_it_encodes_the_ndjson_as_a_collectoon_of_object_from_the_tabular_data(): void
    {
        $json = (new Encoder())->encode(value: $this->setTabularData());
        $lines = explode("\n", trim($json));

        self::assertCount(2, $lines);

        $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Name' => 'Alice', 'Score' => 42], $row);
    }

    public function test_it_encodes_the_ndjson_as_a_collectoon_of_array_from_the_tabular_data(): void
    {
        $json = (new Codec())->encode(value: $this->setTabularData(), format: Format::ListWithHeader, headerOrOffset: 0);
        $lines = explode("\n", trim($json));

        self::assertCount(3, $lines);
        self::assertSame(['Name', 'Score'], json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['Alice', 42], json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_it_writes_the_ndjson_as_a_collectoon_of_object_from_the_tabular_data(): void
    {
        $path = sys_get_temp_dir().'/tabular-object.ndjson';
        @unlink($path);

        $written = ndjson_write($this->setTabularData(), $path);
        self::assertGreaterThan(0, $written);

        /** @var list<string> $contents */
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($contents);
        self::assertCount(2, $contents);

        $row = json_decode($contents[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Name' => 'Bob', 'Score' => 27], $row);
    }

    public function test_it_writes_the_ndjson_as_a_collectoon_of_array_from_the_tabular_data(): void
    {
        $path = sys_get_temp_dir().'/tabular-list.ndjson';
        @unlink($path);

        $written = ndjson_write($this->setTabularData(), $path, format: Format::ListWithHeader);
        self::assertGreaterThan(0, $written);

        /** @var list<string> $contents */
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($contents);
        self::assertCount(3, $contents);

        self::assertSame(['Name', 'Score'], json_decode($contents[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['Bob', 27], json_decode($contents[2], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_it_fails_encoding_tabular_data_to_a_list_if_no_header_is_found(): void
    {
        $data = [
            ['Name', 'Score'],
            ['Alice', '42'],
            ['Bob', '27'],
        ];

        $this->expectException(InvalidNdJsonArgument::class);

        (new Codec())
            ->formatter(function (array $record): array {  /* @phpstan-ignore-line */
                $record[1] = (int) $record[1]; /* @phpstan-ignore-line */

                return $record;
            })
            ->encode($data, format: Format::ListWithHeader);
    }

    public function test_it_returns_an_empty_array_if_no_iterable_data_is_given(): void
    {
        self::assertSame('[]', (new Encoder(chunkSize: 500))->encode(new ArrayIterator([])));
    }

    public function test_it_fails_to_decode_an_invalid_json_string(): void
    {
        $line = '{"foo":"bar"}'."\n".'dassa{d}dsa';
        try {
            iterator_to_array(ndjson_decode($line));
        } catch (DecodingNdJsonFailed $exception) {
            self::assertStringContainsString('Unable to decode the json line:', $exception->getMessage());
            self::assertSame('dassa{d}dsa', $exception->getValue());
            self::assertSame(1, $exception->getOffset());
        }
    }

    public function test_it_fails_to_map_a_valid_json_string(): void
    {
        $line = '{"foo":"bar"}';
        try {
            iterator_to_array((new Codec())->mapper(fn ($value) => throw new RuntimeException())->decode($line));
        } catch (DecodingNdJsonFailed $exception) {
            self::assertStringContainsString('Unable to map the record:', $exception->getMessage());
            self::assertSame(json_decode($line, true), $exception->getValue());
            self::assertSame(0, $exception->getOffset());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_it_fails_to_encode_an_invalid_json_data(): void
    {
        $invalid = tmpfile();
        $data = ['valid', $invalid];
        try {
            ndjson_encode($data);
        } catch (EncodingNdJsonFailed $exception) {
            self::assertStringContainsString('Unable to encode data:', $exception->getMessage());
            self::assertSame($invalid, $exception->getValue());
            self::assertSame(1, $exception->getOffset());
        }
    }

    public function test_it_fails_to_format_a_valid_json_data(): void
    {
        $line = ['valid'];
        try {
            (new Codec())->formatter(fn ($value) => throw new RuntimeException())->encode($line);
        } catch (EncodingNdJsonFailed $exception) {
            self::assertStringContainsString('Unable to format data:', $exception->getMessage());
            self::assertSame('valid', $exception->getValue());
            self::assertSame(0, $exception->getOffset());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_it_fails_to_decode_a_ndjson_as_a_list_with_header_if_no_header_is_found(): void
    {
        $ndjson = <<<LDJSON
["Name","Score","Completed"]
["Gilbert",24,true]
["Alexa",29,true]
LDJSON;

        $this->expectException(InvalidNdJsonArgument::class);

        iterator_to_array(
            (new Codec())->decode($ndjson, format: Format::ListWithHeader),
        );
    }
}
