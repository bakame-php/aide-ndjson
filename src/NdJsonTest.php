<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use League\Csv\ResultSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplTempFileObject;

use function iterator_to_array;

#[CoversClass(NdJson::class)]
final class NdJsonTest extends TestCase
{
    public function test_it_can_encode_to_string(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        self::assertSame($expected, NdJson::encode($data));
    }

    public function test_it_can_decode_to_string(): void
    {
        $data = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";
        $expected = [['key' => 'VALUE'], ['key2' => 'VALUE2']];
        $mapper = fn (array $row) => array_map(strtoupper(...), $row);

        self::assertSame($expected, iterator_to_array(NdJson::decode($data, $mapper)));
    }

    public function test_it_can_encode_to_a_stream(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";

        /** @var resource $file */
        $file = tmpfile();

        NdJson::write($data, $file);
        rewind($file);
        self::assertSame($expected, stream_get_contents($file));
    }

    public function test_it_can_decode_from_a_stream(): void
    {
        $data = '{"key":"value"}'."\n".'{"key2":"value2"}'."\n";
        $expected = [['key' => 'value'], ['key2' => 'value2']];
        $formatter = fn (array $row) => $row;
        $file = new SplTempFileObject();
        $file->fwrite($data);

        self::assertSame($expected, iterator_to_array(NdJson::read($file, $formatter)));
    }

    public function test_it_can_stream_the_encoding(): void
    {
        $data = [['key' => 'value'], ['key2' => 'value2']];
        $expected = ['{"key":"value"}'."\n", '{"key2":"value2"}'."\n"];

        self::assertSame($expected, iterator_to_array(NdJson::chunk($data)));
    }

    public function test_it_can_return_a_tabular_data(): void
    {
        $ldjsonHeader = <<<LDJSON
["Name","Score","Completed"]
["Gilbert",24,true]
["Alexa",29,true]
LDJSON;
        $tabular = NdJson::readTabularFromString($ldjsonHeader);

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
        $tabular = NdJson::readTabularFromString($ldjsonHeader);

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
        $tabular = NdJson::readTabularFromString($ldjsonHeader, $header);

        self::assertSame($header, $tabular->getHeader());
        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertSame('Alexa', $tabular->nth(1)['Name']);
    }

    public function test_it_can_read_nothing_from_an_empty_string(): void
    {
        $tabular = NdJson::readTabularFromString('');
        self::assertInstanceOf(ResultSet::class, $tabular);
        self::assertCount(0, $tabular);
    }
}
