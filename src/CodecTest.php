<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use ArrayIterator;
use League\Csv\Reader;
use League\Csv\ResultSet;
use League\Csv\TabularData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplTempFileObject;
use ValueError;

use function array_map;
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
            ->asArrayWithHeader();

        self::assertNotSame($codec, $newCodec);
        self::assertSame(10, $newCodec->chunkSize);
        self::assertTrue($newCodec->usePrettyPrint());
        self::assertSame(Format::ArrayWithHeader, $newCodec->format);
    }

    public function test_it_can_encode_with_formatter(): void
    {
        $codec = (new Codec())
            ->formatter(fn ($row) => ['value' => $row]);

        $json = $codec->encode([1, 2]);
        $lines = explode("\n", trim($json));

        self::assertCount(2, $lines);
        self::assertSame(['value' => 1], json_decode($lines[0], true));
    }

    public function test_it_can_decode_with_mapper(): void
    {
        $codec = (new Codec())
            ->mapper(fn (array $row): int => $row['n']);

        $data = [
            json_encode(['n' => 5]),
            json_encode(['n' => 10]),
        ];
        $ndjson = implode("\n", $data);

        $decoded = iterator_to_array($codec->decode($ndjson));

        self::assertSame([5, 10], $decoded);
    }

    public function test_it_can_download_a_file(): void
    {
        $header = ['Name', 'Score'];
        $rows = [
            ['Name' => 'Alice', 'Score' => 42],
            ['Name' => 'Bob', 'Score' => 27],
        ];
        $tabular = new ResultSet(new ArrayIterator($rows), $header);
        $codec = new Codec();

        $json = $codec->encode($tabular);
        $lines = explode("\n", trim($json));
        self::assertCount(2, $lines);

        $decoded = $codec->decodeTabularData($json);
        self::assertSame(['Alice', 'Bob'], array_column(iterator_to_array($decoded->getRecords()), 'Name'));
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
        $expected = [['key' => 'VALUE'], ['key2' => 'VALUE2']];
        $mapper = fn (array $row) => array_map(strtoupper(...), $row);

        self::assertSame($expected, iterator_to_array((new Decoder(mapper: $mapper))->decode($data)));
    }

    public function test_it_can_encode_to_a_stream(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        /** @var resource $file */
        $file = tmpfile();

        (new Encoder())->write($data, $file);
        rewind($file);
        self::assertSame($expected, stream_get_contents($file));
    }

    public function test_it_can_decode_from_a_stream(): void
    {
        $data = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";
        $expected = [['key' => 'value'], ['key2' => 'value2']];
        $mapper = fn (array $row) => $row;
        $file = new SplTempFileObject();
        $file->fwrite($data);

        self::assertSame($expected, iterator_to_array((new Decoder(mapper: $mapper))->read($file)));
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
        $tabular = (new Codec())->decodeTabularData($ldjsonHeader, 0);

        self::assertSame(['Name', 'Score', 'Completed'], $tabular->getHeader());
        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertCount(2, $tabular);
    }

    public function test_it_can_return_a_tabular_data_from_a_list_of_object(): void
    {
        $ldjsonHeader = <<<LDJSON
{"name":"Gilbert","score":24,"completed":true}
{"name":"Alexa","score":29,"completed":true}
LDJSON;
        $tabular = (new Codec())->decodeTabularData($ldjsonHeader, 0);

        self::assertSame(['name', 'score', 'completed'], $tabular->getHeader());
        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertCount(2, $tabular);
    }

    public function test_it_can_return_a_tabular_data_from_a_list_of_object_with_different_header(): void
    {
        $header = ['Name', 'Score', 'Completed'];
        $ldjsonHeader = <<<LDJSON
{"name":"Gilbert","score":24,"completed":true}
{"name":"Alexa","score":29,"completed":true}
LDJSON;
        $tabular = (new Codec())->decodeTabularData($ldjsonHeader, headerOrOffset: $header);

        self::assertSame($header, $tabular->getHeader());
        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertSame('Alexa', $tabular->nth(1)['Name']);
    }

    public function test_it_can_read_nothing_from_an_empty_string(): void
    {
        $tabular = (new Codec())->decodeTabularData('');

        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertCount(0, $tabular);
    }

    public function test_it_fails_to_instantiate_an_empty_file(): void
    {
        $this->expectException(ValueError::class);

        (new Codec())->readTabularData(''); /* @phpstan-ignore-line */
    }

    private function setTabularData(): TabularData
    {
        $header = ['Name', 'Score'];
        $records = [
            ['Name' => 'Alice', 'Score' => 42],
            ['Name' => 'Bob', 'Score' => 27],
        ];

        return new ResultSet(new ArrayIterator($records), $header);
    }

    public function test_it_encodes_the_ndjson_as_a_collectoon_of_object_from_the_tabular_data(): void
    {
        $json = (new Encoder())->encode(data: $this->setTabularData());
        $lines = explode("\n", trim($json));

        self::assertCount(2, $lines);

        $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Name' => 'Alice', 'Score' => 42], $row);
    }

    public function test_it_encodes_the_ndjson_as_a_collectoon_of_array_from_the_tabular_data(): void
    {
        $json = (new Codec(format: Format::ArrayWithHeader))->encode(data: $this->setTabularData());
        $lines = explode("\n", trim($json));

        self::assertCount(3, $lines);
        self::assertSame(['Name', 'Score'], json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['Alice', 42], json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_it_writes_the_ndjson_as_a_collectoon_of_object_from_the_tabular_data(): void
    {
        $path = sys_get_temp_dir().'/tabular-object.ndjson';
        @unlink($path);

        $written = (new Encoder())->write($this->setTabularData(), $path);
        self::assertGreaterThan(0, $written);

        /** @var array<string> $contents */
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(2, $contents);

        $row = json_decode($contents[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Name' => 'Bob', 'Score' => 27], $row);
    }

    public function test_it_writes_the_ndjson_as_a_collectoon_of_array_from_the_tabular_data(): void
    {
        $path = sys_get_temp_dir().'/tabular-list.ndjson';
        @unlink($path);

        $written = (new Codec())->asArrayWithHeader()->write($this->setTabularData(), $path);
        self::assertGreaterThan(0, $written);

        /** @var array<string> $contents */
        $contents = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(3, $contents);

        self::assertSame(['Name', 'Score'], json_decode($contents[0], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['Bob', 27], json_decode($contents[2], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_it_fails_encoding_tabular_data_to_a_list_if_no_header_is_found(): void
    {
        $csv = <<<CSV
Name,Score
Alice,42
Bob,27
CSV;
        $this->expectException(InvalidNdJsonArgument::class);

        (new Codec())
            ->formatter(function (array $record): array {
                $record[1] = (int) $record[1];

                return $record;
            })
            ->asArrayWithHeader()
            ->encode(Reader::createFromString($csv));
    }

    public function test_it_returns_an_empty_array_if_no_iterable_data_is_given(): void
    {
        self::assertSame('[]', (new Encoder(chunkSize: 500))->encode(new ArrayIterator([])));
    }
}
