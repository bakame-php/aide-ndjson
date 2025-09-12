# Aide for NDJSON

A robust NDJSON?JSONL Encoder/Decoder for PHP

## Introduction

`NdJson` is a robust PHP utility for **encoding, decoding, streaming, and tabular parsing of NDJSON (Newlinw-Delimited JSON)**
— also commonly known as **JSON Lines (JSONL)**. Both names refer to the same format: one JSON object per line, separated by `\n`.

It supports both **object-row** and **list-header** formats, streaming iterators, and static-analysis-friendly types for PHPStan/Psalm.

## Installation

### Composer

~~~
composer require bakame-php/aide-ndjson
~~~

### System Requirements

You need:

- **PHP >= 8.1** but the latest stable version of PHP is recommended
- latest version of `league/csv`

## Usage

```php
use function Bakame\Aide\NdJson\Codec;

// Fetch data from the database
$stmt = $connection->prepare("SELECT first_name, last_name, email, phone FROM clients");
$stmt->setFetchMode(PDO::FETCH_ASSOC);
$stmt->execute();

// Configure the NDJSON encoder
$encoder = new Codec()
    ->asArrayWithHeader()
    ->withUnescapedUnicode()
    ->withUnescapedSlashes();

// Export query results to file
$bytes = $encoder->write(data: $stmt, to: 'path/to/clients.jsonl', headerOrOffset: 0);
```

This example exports rows from the `clients` table into a NDJSON
file at `path/to/clients.ndjson`. The output will look like:

```json
["first_name","last_name","email","phone"]
["","","alexandre@toto.com",null]
["Mimi","Mimi","toto@mimi.com","+24134456789"]
["prenom1","prenom1","toto@example.com",null]
["","","joe@example.com","+34434456789"]
["","","","+23734456789"]
```

Here:

- The first row is used to extract the header, taken from the `headerOrOffset` argument.
- Each row is serialized as a JSON array rather than an object, reducing file size.
- The encoder options (`withUnescapedUnicode`, `withUnescapedSlashes`) are JSON options to make the output more human-readable.

## Documentation

### Codec

The `Codec` class provides a **fluent, immutable API** for configuring and
working with `NdJson` documents. It lets you define reusable defaults
(such as mappers, formatters, chunk size, encoding flags, and record format) and
then apply them consistently across multiple operations. It is the main entry point
to the package.

```php
use Bakame\Aide\NdJson\Codec;

$codec = new Codec();
```
Each configuration method returns a **new instance**. This ensures immutability
and allows for safely sharing base configurations.

### Configuration

#### JSON options

Because NDJSON contains JSON structure, `Codec` allows you to set JSON flags for encoding or decoding.

```php
public Codec::addFlags(int ...$flag): self
public Codec::removeFlags(int ...$flag): self
public Codec::useFlags(int ...$flag): bool
```

These methods set the JSON flags to be used during conversion. The builder handles all the
flags supported by PHP `json_encode` and `json_decode` functions.

If you prefer a more expressive way for setting flags, you can use the `with*` and `without*` methods
whose names are derived from PHP JSON constants.

```php
use function Bakame\Aide\NdJson\ndjson;

$codec = (new Codec())
    ->addFlags(JSON_UNESCAPED_SLASHES)
    ->removeFlags(JSON_HEX_QUOT);

//is equivalent to

$codec = (new Codec())
    ->withUnescapedSlashes()
    ->withoutHexQuot();
```

> [!WARNING]
> During encoding, the `JSON_PRETTY_PRINT` flags is ignored to avoid generating invalid NDJSON.

> [!WARNING]
> During decoding the `JSON_OBJECT_AS_ARRAY`, `JSON_FORCE_OBJECT` flags are ignored.

> [!NOTE]
> the `JSON_THROW_ON_ERROR` flag is always used even if it is not set.

### Encoding NDJSON

#### Encoding options

During encoding apart from the JSON options, you can specify:

- Chunk Size: `chunkSize(int $size): self`: Controls how many records are grouped together per JSON chunk (default: 1).
- Record Formatting: `formatter(?callable $formatter): self` Transform each record before encoding.
- Document Format `format(Format $format): self` Defines the output representation.

```php
$codec = (new Codec())
    ->formatter(fn ($row) => ['value' => $row])
    ->chunkSize(100)
    ->withUnescapedSlashes()
    ->asArrayWithHeader();
```

> [!NOTE]
> Make sure your formatter always returns a PHP array for improved generation.

#### Decoding Methods

Different encoding strategies are supported, depending on how you want to generate your
NDJSON content. You can encode using:

- the `encode()` method to output a string;
- the `write()` method to store the output into a file ;
- the `download()` method to encode and make the file downloadable via any HTTP client.

for advanced users, there is also:

- the `chunk()` method to output the document by chunks of string using a `Iterator`;

#### Encode an array of data to NDJSON/JSONL string

```php
$data = [
    ['name' => 'Alice', 'score' => 42],
    ['name' => 'Bob', 'score' => 27],
];

$ndjson = (new Codec())->encode($data);
echo $ndjson;

/*
{"name":"Alice","score":42}
{"name":"Bob","score":27}
*/
```

#### Write NDJSON/JSONL directly to a file

```php
$data = [
    ['user' => 'Charlie', 'active' => true],
    ['user' => 'Diana', 'active' => false],
];

(new Codec())->write(data: $data, to: __DIR__ . '/users.ndjson');
```

#### Make NDJSON downloadable via HTTP

```php
// In a controller:
(new Codec())->download(
    data: [
        ['id' => 1, 'value' => 'foo'], 
        ['id' => 2, 'value' => 'bar'],
    ],
    filename: 'export.ndjson'
);
```

#### Encoding with a formatter

You can use a `callback` as a formatter to format your data prior to it being JSON encoded:

```php
(new Codec())
    ->formatter(function (array $data): {
        $data['id'] = $data['id'] * 5;
        
        return $data;
    })
    ->encode(data: [
        ['id' => 1, 'value' => 'foo'], 
        ['id' => 2, 'value' => 'bar'],
    ]);
/*
{"id":5, "value":"foo"}
{"id":10", "value":"foo"}
*/
```

#### Encoding Tabular Data

The encoding methods support encoding `League\Csv\TabularData` implementing classes
as shown below using the `encode()` method.

```php
use Bakame\Aide\NdJson\Codec;
use Bakame\Aide\NdJson\Format;
use League\Csv\Reader;

$csv = <<<CSV
Name,Score
Alice,42
Bob,27
CSV;

$document = Reader::createFromString($csv);
$document->setHeaderOffset(0);

$codec = new Codec();

$json = $codec
    ->encode($document);
// NDJSON with object records
// {"Name":"Alice","Score":42}
// {"Name":"Bob","Score":27}

$json = $codec
    ->withArrayFormat()
    ->encode($document);
// NDJSON with arrays and no
// ["Alice",42]
// ["Bob",27]

$json = $codec
    ->asArrayWithHeader()
    ->encode($document);
// NDJSON with arrays and a header
// ["Name","Score"]
// ["Alice",42]
// ["Bob",27]

$json = $codec
    ->asArrayWithHeader()
    ->encode($document, ['Nom', 'Score']);
// NDJSON with arrays and a header
// ["Nom","Score"]
// ["Alice",42]
// ["Bob",27]
```

> [!WARNING]
> If your data is not a `League\Csv\TabularData` implementing class with a defined header
> you MUST provide a list as header or an offset to the collection pointing to the
> header to be able to use the format `Format::ArrayWithheader` Otherwise an
> exception will be thrown.

### Decoding NDJSON

#### Decoding options

During decoding apart from the JSON options, you can specify:

- Mapper: `mapper(?callable $mapper): self` Transform each decoded row into another representation.

#### Decoding methods

- the `decode()` method to decode a NDJSON/JSONL string
- the `read()` method to decode NDJSON/JSONL content from a file or a stream;

Both methods act the same way and accept the same parameters; the only difference 
is that the `read` method expect a string as being a path, whereas the `decode`
method will treat it as being the actual NDJSON string.

#### Decode a string

each decoded NDJSON line will be represented as an associative array.

```php
$content = <<<NDJSON
{"name":"Alice","score":42}
{"name":"Bob","score":27}
NDJSON;

foreach (new Codec())->decode($content) as $row) {
    var_dump($row);
}
/*
array(2) { ["name"]=> string(5) "Alice" ["score"]=> int(42) }
array(2) { ["name"]=> string(3) "Bob"   ["score"]=> int(27) }
*/
```

#### Decoding with mapper

If you want each line to be converted into another structure, you can use the
`callback` mapper which will expect the returned associative array as input.

```php
$content = <<<NDJSON
{"value":1}
{"value":2}
{"value":3}
NDJSON;

$iterator = (new Codec())
    ->mapper(fn (array $row): int => $row['value'] * 10)
    ->decode($content);

foreach ($iterator as $num) {
    echo $num, PHP_EOL;
}
/*
10
20
30
*/
```

#### Decoding Tabular data

The `Codec` parses NDJSON as **tabular data** using `league\csv` package features.
Two methods are provided `decodeTabularData` to parse inline content
and `readTabularData` to parse files content or stream.

```php
decodeTabularData(Stringable|string $content, array|int $headerOrOffset = []): TabularData
readTabularData(mixed $from, $context = null, array|int $headerOrOffset = []): TabularData
```

Because `NDJSON` content can have different formats:

- **Object rows**: `{"Name":"Gilbert","Score":24}`
- **List rows with header**: ["Name","Score"] followed by value arrays
- **List rows without header**: only value arrays are included; any header row is ignored if present

You can configure the expected NDJSON format to be parsed using the `format()` method, which accepts
one of the `Format` enum cases:

- `Format::Default`
- `Format::ArrayWithHeader`
- `Format::Array`


```php
$tabular = (new Codec())->decodeTabularData($ldjsonString);
foreach ($tabular as $record) {
    var_dump($record);
}
```

Both methods return a `TabularData` instance that can be further processed using all the
features from the `League\Csv` package.

```php
use League\Csv\Statement;

$query = (new Statement())
    ->andWhere('score', '=', 10) 
    ->whereNot('name', 'starts_with', 'P') //filtering is done case-sensitively on the first character of the column value;

$tabular = (new Codec())->readTabularData('/tmp/scores.ndjson');
foreach ($query->process($tabular)->getRecordsAsObject(Player::class) as $player) {
    echo $player->name, PHP_EOL;
}
```
