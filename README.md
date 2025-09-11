# NdJson — Lightweight NDJSON Encoder/Decoder

`NdJson` is a robust PHP utility for **encoding, decoding, streaming, and tabular parsing of NDJSON (Newlinw-Delimited JSON)**
— also commonly known as **JSON Lines (JSONL)**.
Both names refer to the same format: one JSON object per line, separated by `\n`.

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

### Encoding NDJSON

#### Encode to a string

```php
encode(iterable $data, ?callable $formatter = null): string
```

Converts an array or iterable of JSON-encodable values to NDJSON string.

```php
use Bakame\Aide\NdJson\NdJson;

$ldjson = NdJson::encode($data);
```

Optional `$formatter` allows transforming each element before encoding:

```php
$ldjson = NdJson::encode($data, fn (Player $user) => $player->toArray());
```

#### Encode to a stream (file)

```php
write(iterable $data, mixed $to, ?callable $formatter = null, $context = null): int
```

Writes NDJSON to a file, stream, or resource. Returns the number of bytes written:

```php
NdJson::write($data, '/tmp/scores.jsonl');
```

#### Stream while encoding

```php
chunk(iterable $data, ?callable $formatter = null): Iterator
```

Streams NDJSON line by line as an iterator:

```php
foreach (NdJson::chunk($data) as $line) {
    echo $line, "\n";
}
```

#### Encode and download

```php
download(iterable $data, ?string $filename = null, ?callable $formatter = null): int
```

Outputs NDJSON via HTTP. Returns the number of characters sent.

```php
NdJson::download($data, 'scores.ldjson');
```

> [!NOTE] Intended for web context (brownser download)

### Decoding NDJSON

#### Decode a string

```php
decode(Stringable|string $content, ?callable $mapper = null): Iterator
```

Parses NDJSON from string and optionally maps each row:

```php
$iterator = NdJson::decode($ldjsonString);
foreach ($iterator as $row) {
    print_r($row);
}

// Mapping to objects
$objects = NdJson::decode($ldjsonString, fn($row) => (object)$row);
```

#### Decode a stream (file)

```php
read(mixed $from, ?callable $mapper = null, $context = null): Iterator
```

Reads NDJSON from file, stream, resource, or a path referenced by a string:

```php
$iterator = NdJson::read('/tmp/scores.ldjson');
```

## Tabular parsing

NDJSON can represent **tabular data** in two forms:

- **Object rows**: `{"Name":"Gilbert","Score":24}`
- **List rows with header**: ["Name","Score"] followed by values

```php
readTabularFromPath(mixed $path, array $header = [], $context = null): TabularData
readTabularFromString(Stringable|string $content, array $header = []): TabularData
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
