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
- latest version of league/csv

## Usage

The package public API is a collection of static methods declared on the `NdJson` class.

### Encoding NDJSON

Different encoding strategies are supported, depending on how you want to generate your
NDJSON content. You can encode using:

- the `encode()` method to output a string
- the `write()` method to store the output into a file ;
- the `chunk()` method to generate a NDJSON by chunks of string
- the `download()` method to encode and make the file downloadable via any HTTP client.

#### Encode an array of data to NDJSON/JSONL string

```php
$data = [
    ['name' => 'Alice', 'score' => 42],
    ['name' => 'Bob', 'score' => 27],
];

$ndjson = NdJson::encode($data);
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

NdJson::write($data, __DIR__ . '/users.ndjson');
```

#### Make NDJSON downloadable via HTTP

```php
// In a controller:
return NdJson::download(
    [['id' => 1, 'value' => 'foo'], ['id' => 2, 'value' => 'bar']],
    filename: 'export.ndjson'
);
```

### Decoding NDJSON

The `NdJson` class allows you to also decode NDJSON/JSONL document using:

- the `decode()` method to decode a NDJSON/JSONL string
- the `read()` method to retrieve NDJSON/JSONL conten from a file;

#### Decode a string

```php
$content = <<<NDJSON
{"name":"Alice","score":42}
{"name":"Bob","score":27}
NDJSON;

foreach (NdJson::decode($content) as $row) {
    var_dump($row);
}
/*
array(2) { ["name"]=> string(5) "Alice" ["score"]=> int(42) }
array(2) { ["name"]=> string(3) "Bob"   ["score"]=> int(27) }
*/
```

### Decode with mapper


```php
$content = <<<NDJSON
{"value":1}
{"value":2}
{"value":3}
NDJSON;

$iterator = NdJson::decode($content, fn (array $row): int => $row['value'] * 10);

foreach ($iterator as $num) {
    echo $num, PHP_EOL;
}
/*
10
20
30
*/
```

## Tabular parsing

NDJSON can represent **tabular data** in two forms:

- **Object rows**: `{"Name":"Gilbert","Score":24}`
- **List rows with header**: ["Name","Score"] followed by values

```php
readTabularFromPath(mixed $path, array $header = [], $context = null): TabularData
decodeTabularFromString(Stringable|string $content, array $header = []): TabularData
```

Parses a file or stream as tabular data. Auto-detects headers if `$header` is empty.

```php
$tabular = LdJson::readTabularFromString($ldjsonString);
foreach ($tabular as $record) {
    var_dump($record);
}
```

The returned `TabularData` can be further processed using all the features from the
`League\Csv` package.

```php
use League\Csv\Statement;

$query = (new Statement())
    ->andWhere('score', '=', 10) 
    ->whereNot('name', 'starts_with', 'P') //filtering is done case-sensitively on the first character of the column value;

$tabular = LdJson::readTabularFromPath('/tmp/scores.ldjson');
foreach ($query->process($tabular)->getRecordsAsObject(Player::class) as $player) {
    echo $player->name, PHP_EOL;
}
```
