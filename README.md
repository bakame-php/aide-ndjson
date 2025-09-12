# Aide for NDJSON

A robust NDJSON Encoder/Decoder for PHP

## Introduction

`aide-ndjson` is a robust PHP utility for **encoding, decoding, streaming, and tabular parsing of NDJSON (Newline-Delimited JSON)**
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

## Quick Usage

This package provides four helper functions define under the `Bakame\Aide\Ndjson` namespace
for working with **NDJSON (Newline Delimited JSON)** data.

`ndjson_encode()` — Encodes an iterable of JSON-serializable value into an NDJSON-formatted string.####

```php
ndjson_encode(
    iterable $value,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
): string
```

#### example

```php
use function Bakame\Aide\NdJson\ndjson_encode;

$data = new ArrayIterator([
    ['name' => 'Alice', 'score' => 42],
    ['name' => 'Bob', 'score' => 27],
]);

echo ndjson_encode($data);
//will output
// {"name":"Alice","score":42}
// {"name":"Bob","score":27}
// 
```

`ndjson_decode()` — Decodes an NDJSON string into a PHP iterable structure of associative arrays, where each line represents a JSON record.

```php
ndjson_decode(
    Stringable|string $ndjson,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
): Iterator
```

#### example

```php
$content = <<<NDJSON
{"name":"Alice","score":42}
{"name":"Bob","score":27}
NDJSON;

var_dump(iterator_to_array(ndjson_decode($content)));
// [
//    ['name' => 'Alice', 'score' => 42],
//    ['name' => 'Bob', 'score' => 27],
// ]
```

`ndjson_write()` — Behaves like `ndjson_encode()`, but writes the resulting NDJSON data to a file or stream.

```php
ndjson_write(
    iterable $value,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
    $context = null,
): int
```

### Example

```php
$data = new ArrayIterator([
    ['name' => 'Alice', 'score' => 42],
    ['name' => 'Bob', 'score' => 27],
]);

$bytes = ndjson_write(data: $data, to: 'path/to/stored.ndjson');
// the file 'path/to/store.ndjson' will contain
// {"name":"Alice","score":42}
// {"name":"Bob","score":27}
```

`ndjson_read()` — Behaves like `ndjson_decode()`, but reads NDJSON data directly from a file or stream.

```php
ndjson_read(
    mixed $from,
    int $flags = 0,
    int $depth = 512,
    $format = Format::Record,
    $context = null
): Iterator
```

### Example

```php
$content = <<<NDJSON
{"name":"Alice","score":42}
{"name":"Bob","score":27}
NDJSON;

var_dump(iterator_to_array(ndjson_read(from: 'path/to/stored.ndjson')));
// [
//    ['name' => 'Alice', 'score' => 42],
//    ['name' => 'Bob', 'score' => 27],
// ]
```

**All 4 (four) functions uses the same arguments as `json_encode` and `json_decode`**.
They only differ by the fact that they:

- Silently ignore the `JSON_PRETTY_PRINT` flag
- Always uses the `JSON_THROW_ONR_ERROR` flag
- Always decode JSON records as associative arrays.

## Advanced Usage

For more advanced use cases, you can work directly with the `Codec` class.
The helper functions are simply convenient shortcuts for its core features.

### Example: Exporting Database Results

```php
use Bakame\Aide\NdJson\Codec;
use Bakame\Aide\NdJson\Format;

// Fetch data from the database
$stmt = $connection->prepare(
    "SELECT first_name, last_name, email, phone FROM clients"
);
$stmt->setFetchMode(PDO::FETCH_ASSOC);
$stmt->execute();

// Configure the NDJSON encoder
$encoder = new Codec()
    ->withUnescapedUnicode()
    ->withUnescapedSlashes()
    ->formatter(function (array $record): array {
        $record['last_name'] = strtoupper($record['last_name'])
    })

// The Codec is immutable — you can safely re-use it
// across multiple operations without side effects.

// Export query results to file
$bytes = $encoder->write(
    data: $stmt,
    to: 'path/to/clients.ndjson',
    format: Format::ListWithHeader,
    headerOrOffset: ['Prénom', 'Nom de Famille',  'Email', 'Mobile'],
);
```

This example exports rows from the `clients` table into an NDJSON file at
`path/to/clients.ndjson`. The resulting file will look like this:

```json
["Prénom","Nom de Famille","Email","Mobile"]
["","","alexandre@toto.com",null]
["Mimi","MIMI","toto@mimi.com","+24134456789"]
["prenom1","NOM1","toto@example.com",null]
["","","joe@example.com","+34434456789"]
["","","","+23734456789"]
```

### Explanation

 - **Header extraction:** The first row (index 0) is used to determine the header fields, as defined by the `$headerOrOffset` argument.
 - **Array-based serialization:** Each record is encoded as a JSON array instead of an object, which can significantly reduce file size.
 - **Encoding options:**

    - `withUnescapedUnicode()` – keeps non-ASCII characters readable (e.g. `é` instead of `\u00e9`).
    - `withUnescapedSlashes()` – avoids escaping slashes (`/`).

 These are standard JSON flags that make the output easier to read while remaining fully valid NDJSON.

- **Formatter callback** formats the record before conversion into a JSON record.
- **Header replaced** the header values are replaced by custom ones

## Documentation

### Codec

The `Codec` class provides a **fluent, immutable API** for configuring and
working with NDJSON documents. It allows you to define reusable defaults — 
such as mappers, formatters, chunk size, encoding flags, and record 
format —
and apply them consistently across multiple read/write operations.

It is the **main entry point** to the package.

```php
use Bakame\Aide\NdJson\Codec;

$codec = new Codec();
```
Each configuration method returns a **new instance** of `Codec`. This ensures immutability
and makes it safe to share base configurations across your application.

### Configuration

#### JSON options

Since NDJSON is built on top of JSON, the `Codec` class lets you control the JSON encoding and decoding flags used internally.

```php
public Codec::addFlags(int ...$flag): self
public Codec::removeFlags(int ...$flag): self
public Codec::useFlags(int ...$flag): bool
```

These methods configure the JSON flags used during conversion. All flags supported by PHP's
native `json_encode()` and `json_decode()` are accepted.

If you prefer a more expressive way for setting flags, you can use the `with*()` and 
`without*()` methods whose names correspond to PHP's JSON constants.

```php
use Bakame\Aide\NdJson\Codec;

// Using numeric flags
$codec = (new Codec())
    ->addFlags(JSON_UNESCAPED_SLASHES)
    ->removeFlags(JSON_HEX_QUOT);

// Equivalent, using expressive methods
$codec = (new Codec())
    ->withUnescapedSlashes()
    ->withoutHexQuot();
```

> [!WARNING]
> During encoding, the `JSON_PRETTY_PRINT` flags is silently ignored,
> since pretty-printed JSON is **not compatible** with NDJSON format.

> [!WARNING]
> During decoding, the `JSON_OBJECT_AS_ARRAY`, `JSON_FORCE_OBJECT` flags are ignored.
> NDJSON records are **always parsed as associative arrays.**

> [!NOTE]
> the `JSON_THROW_ON_ERROR` flag is **always enabled** even if not explicitly set.

The `depth()` method can be used to control the **maximum JSON nesting depth** passed
internally to PHP’s `json_encode()` and `json_decode()` functions.

### Encoding NDJSON

#### Encoding options

> [!NOTE]
> `chunkSize()` and `formatter()` method are **only** available when using the `Codec` class.

During encoding, you can customize how data is transformed and written using the following options:

##### chunkSize():

```php
chunkSize(int $size): self
```

Defines how many records are grouped together per NDJSON write operation (default: `1`).

```php
$codec = (new Codec())
    ->chunkSize(100)
    ->withUnescapedSlashes();
```

When encoding, up to `100` records will be buffered before being flushed to the output.
This can improve performance when working with large datasets or streaming data to disk.

##### formatter()

```php
formatter(?callable $callback): self
```
Applies a custom transformation to each record before encoding.
The provided callable receives the record and must return the transformed version.

```php
(new Codec())
    ->formatter(function (array $data): array {
        $data['id'] = $data['id'] * 5;
        
        return $data;
    })
    ->encode([
        ['id' => 1, 'value' => 'foo'], 
        ['id' => 2, 'value' => 'bar'],
    ]);
/*
{"id":5, "value":"foo"}
{"id":10", "value":"bar"}
*/
```

> [!NOTE]
> While not strictly required, it is recommended that your formatter always returns a
> PHP array for maximum compatibility with NDJSON encoders and downstream consumers.

#### Encoding Methods

The Codec supports multiple encoding strategies, depending on how you want to produce your
NDJSON output:

- `encode()` — returns the complete encoded NDJSON as a string.
- `chunk()` — yields encoded NDJSON data in chunks using an Iterator.
- `write()` — writes the complete encoded data directly to a file or stream.
- `download()` — encodes and streams NDJSON as a downloadable HTTP response.

#### Encode data to NDJSON string

```php
$data = [
    ['name' => 'Alice', 'score' => 42],
    ['name' => 'Bob', 'score' => 27],
];

echo (new Codec())->encode($data);
echo ndjson_encode($data);

/*
{"name":"Alice","score":42}
{"name":"Bob","score":27}
*/
```

#### Write NDJSON directly to a file

```php
$data = [
    ['user' => 'Charlie', 'active' => true],
    ['user' => 'Diana', 'active' => false],
];

(new Codec())->write(value: $data, to: __DIR__ . '/users.ndjson');
ndjson_write(value: $data, to: __DIR__ . '/users.ndjson')
```
> [!NOTE]
> When working with streams, you can use the optional `$context` parameter to optimize writing
> (for example, when storing data to third-party storage accessible via a stream wrapper).

#### Make NDJSON downloadable via HTTP

> [!NOTE]
> `download()` method is **only** available when using the `Codec` class.

```php
// In a controller outside a framework:
(new Codec())->download(
    data: [
        ['id' => 1, 'value' => 'foo'], 
        ['id' => 2, 'value' => 'bar'],
    ],
    filename: 'export.ndjson'
);
```
This method automatically sets the appropriate headers and streams the NDJSON data as
a downloadable response. The script ends after the stream is set and consumed.

> [!NOTE]
> If your project uses a framework, consider using its provided streamed response 
> class to avoid bypassing the framework’s normal request–response flow. You can
> then take advantage of the `Codec::chunk()` method instead.

### Decoding NDJSON

#### Decoding options

During decoding, in addition to JSON options, you can configure how each record is mapped
to a PHP structure.

##### mapper()

```php
mapper(?callable $mapper): self
```

Deserializes each decoded record before being returned by the decoding method.
The callable receives the decoded associative array and must return the transformed value.

> [!NOTE]
> `mapper()` method is **only** available when using the `Codec` class.

If you want each line to be converted into another structure, you can use the
`callback` mapper which will expect the raw-decoded data just after using `json_decode`.

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

#### Decoding methods

The `Codec` supports two decoding strategies:

`decode()` — Decodes an NDJSON **string;**

```php
Codec::decode(
    Stringable|string $ndjson,
    Format $format = Format::Record,
    array|int $headerOrOffset = []
): Iterator
```

`read()` — Decodes NDJSON content from a **file** or a **stream**;

```php
Codec::read(
    mixed $from,
    Format $format = Format::Record,
    array|int $headerOrOffset = [],
    $context = null
): Iterator
```

Both methods behave the same way and accept the same parameters.
The only difference is that `read()` expects a file path or stream,
whereas `decode()` expects the actual NDJSON string.

#### Decode a string

Each decoded NDJSON line is returned as an associative array.

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

### Tabular Data

> [!NOTE]
> **full control over encoding tabular data is only available** when using the `Codec` class.
> You can still handle the basic tabular data using the 4 (four) helper functions and their
> optional `$format` argument.

#### Encoding

All encoding methods support **tabular data.** via two parameters:

- The `$format` parameter which indicate the NDJSON format.
- The `$headerOrOffset` parameter which defines the header values; **only available when using the `Codec` class.**

> [!WARNING]
> To work as expected, each item of your iterable input MUST be convertable
> into an array otherwise an Exception will be thrown.

Let's use the `Codec::encode()` method to illustrate the feature.

```php
use Bakame\Aide\NdJson\Codec;
use Bakame\Aide\NdJson\Format;

$data = [
    ['Name' => 'Alice', 'Score' => 42],
    ['Name' => 'Bob', 'Score' => 27],
];

$codec = new Codec();

$json = $codec
    ->encode(value: $document);
// NDJSON with object records
// {"Name":"Alice","Score":42}
// {"Name":"Bob","Score":27}

$json = $codec
    ->encode(value: $document, format: Format::List);

//we have specified the record used for header information
// NDJSON with arrays and no header
// ["Alice",42]
// ["Bob",27]

$json = $codec
    ->encode(value: $document, format: Format::ListWithHeader, headerOrOffset: 0);

// NDJSON with arrays and a header
// ["Name","Score"]
// ["Alice",42]
// ["Bob",27]

$json = $codec
    ->encode(value: $document, format: Format::ListWithHeader, headerOrOffset: ['Nom', 'Score']);

// NDJSON with arrays and a header
// ["Nom","Score"]
// ["Alice",42]
// ["Bob",27]
```

> [!WARNING]
> When using `Format::ListWithHeader,` you must provide either:
> a header list, or a record offset to extract the header from. 
> Otherwise, an exception will be thrown.

#### Decoding

Just like with the encoding methods, to read tabular data, you must provide the
expected JSON format and the optional header information when needed.

Let's illustrate the behavior with the following NDJSON:

```php
$ndjson = <<<LDJSON
["Name","Score","Completed"]
["Gilbert",24,true]
["Alexa",29,true]
LDJSON;
```

Using the default options, you will get the following output:

```php
$tabular = (new Codec())->decode(ndjson: $ndjson);

var_dump(iterator_to_array($tabular));
// [
//     ["Name", "Score", "Completed"],
//     ["Gilbert",24,true],
//     ["Alexa", 29,true],
// ]
```
if you provide a header offset, the output will change as follows:

```php
$tabular = (new Codec())->decode(ndsjon: $ndjson, headerOffset: 0);

var_dump(iterator_to_array($tabular));
// [
//     ["Name" => "Name", "Score" => "Score, "Completed" => "Completed"],
//     ["Name" => "Gilbert", "Score" => 24, "Completed" => true],
//     ["Name" => "Alexa", "Score" => 29, "Completed" => true],
// ]
```
The method will use the JSON record to map property names **BUT** the
first row is still present. We need to inform the `Codec` instance
about the NDJSON format:

```php
$tabular = (new Codec())
    ->decode(
    ndjson: $ndjson, 
    format: Format::ListWithHeader, 
    headerOffset: 0
);

var_dump(iterator_to_array($tabular));
// [
//     1 => ["Name" => "Gilbert", "Score" => 24, "Completed" => true],
//     2 => ["Name" => "Alexa", "Score" => 29, "Completed" => true],
// ]
```

The header NDJSON is skipped (the **`0` offset is missing when iterating over the returned `Iterator`**)

Just like with the encoding methods, you can swap the header information completely with
a custome header:

```php
$tabular = (new Codec())
    ->decode(
    ndjson: $ndjson, 
    format: Format::ListWithHeader, 
    headerOffset: ['NOM', 'SCORE', 'TERMINE']
);

var_dump(iterator_to_array($tabular));
// [
//     1 => ["NOM" => "Gilbert", "SCORE" => 24, "TERMINE" => true],
//     2 => ["NOM" => "Alexa", "SCORE" => 29, "TERMINE" => true],
// ]
```

> [!NOTE]
> You can further modify the output using the `mapper()` method from the `Codec`
> Il will be called after the optional header resolution and record generation.

Contributing
-------

Contributions are welcome and will be fully credited. Please see [CONTRIBUTING](.github/CONTRIBUTING.md) and [CODE OF CONDUCT](.github/CODE_OF_CONDUCT.md) for details.

Testing
-------

The library has a :

- a [PHPUnit](https://phpunit.de) test suite
- a coding style compliance test suite using [PHP CS Fixer](https://cs.sensiolabs.org/).
- a code analysis compliance test suite using [PHPStan](https://github.com/phpstan/phpstan).

To run the tests, run the following command from the project folder.

``` bash
$ composer test
```

Security
-------

If you discover any security related issues, please email nyamsprod@gmail.com instead of using the issue tracker.

Credits
-------

- [ignace nyamagana butera](https://github.com/nyamsprod)
- [All Contributors](https://github.com/thephpleague/uri-src/contributors)

License
-------

The MIT License (MIT). Please see [License File](LICENSE) for more information.
