This 'zephir-prep' branch is experimental, inspired by the discovery that Zephir does not do
object references.

The PHP toml implementations I have seen all used array reference (array& $aref) 
to indicate place in array tree.
The alternative, using wrapper PHP , which are references, adds indirection and loses a small amount of efficiency, but it 
also enables the parse to construct an object tree, before a final toArray() conversion.
The object tree may be useful as TOML tree object builder as well.

Table and TableList objects
=========================

Zephir currently does not do reference operations, especially for arrays (&) 
This implementation uses objects Table, TableList, and ValueList.
Table uses the internal properties table of PHP objects.
TableList implements array of Table.

A toArray() method will have to serve to return a native PHP array representation. The object structure is likely to be similar to the nestable Phalcon\Config
object, which is part of the zephir compiled Phalcon php-extension website framework.
Phalcon\Config stores keyed properties in the PHP object. Read access to these has php-object property efficiency.
As all keys of Phalcon\Config are represented as strings, per requirement of PHP object properties, TOML is okay with that.

Array of Tables - AOT, needs an object
---------------------------------------

Phalcon\Config implements \ArrayAccess interface, on string object properties,
 but this is not compatible with a numeric key index. Therefore another object class is required to mediate numeric
key access, such that its \ArrayAccess interface uses a real PHP array, rather than the internal property table of the object.

TOML parser for PHP
===================

A PHP parser for [TOML](https://github.com/toml-lang/toml) compatible with [TOML v0.4.0](https://github.com/toml-lang/toml/releases/tag/v0.4.0).

[![Build Status](https://travis-ci.org/yosymfony/toml.png?branch=master)](https://travis-ci.org/yosymfony/toml)
[![Latest Stable Version](https://poser.pugx.org/yosymfony/toml/v/stable.png)](https://packagist.org/packages/yosymfony/toml)
[![Total Downloads](https://poser.pugx.org/yosymfony/toml/downloads.png)](https://packagist.org/packages/yosymfony/toml)

Support:

[![Gitter](https://badges.gitter.im/Join%20Chat.svg)](https://gitter.im/yosymfony/Toml?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)

Installation
------------
**Requires PHP >= 7.1.**

Use [Composer](http://getcomposer.org/) to install this package:

```bash
composer require yosymfony/toml
```

Usage
-----
You can parse an inline TOML string or from a file:

To parse an inline TOML string:

```php
use Yosymfony\Toml\Toml;

$array = Toml::Parse('key = [1,2,3]');

print_r($array);
```

To parse a TOML file:

```php
$array = Toml::ParseFile('example.toml');

print_r($array);
```

Additionally, methods `parse` and `parseFile` accept a second argument called
`resultAsObject` to return the result as an object based on `stdClass`.

```php
$object = Toml::Parse('key = [1,2,3]', true);
```

### TomlBuilder
You can create a TOML string with TomlBuilder. TomlBuilder uses a *fluent interface* for more readable code:

```php
    use Yosymfony\Toml\TomlBuilder;

    $tb = new TomlBuilder();

    $result = $tb->addComment('Toml file')
        ->addTable('data.string')
        ->addValue('name', "Toml", 'This is your name')
        ->addValue('newline', "This string has a \n new line character.")
        ->addValue('winPath', "C:\\Users\\nodejs\\templates")
        ->addValue('literal', '@<\i\c*\s*>') // literals starts with '@'.
        ->addValue('unicode', 'unicode character: ' . json_decode('"\u03B4"'))

        ->addTable('data.bool')
        ->addValue('t', true)
        ->addValue('f', false)

        ->addTable('data.integer')
        ->addValue('positive', 25, 'Comment inline.')
        ->addValue('negative', -25)

        ->addTable('data.float')
        ->addValue('positive', 25.25)
        ->addValue('negative', -25.25)

        ->addTable('data.datetime')
        ->addValue('datetime', new \Datetime())

        ->addComment('Related to arrays')

        ->addTable('data.array')
        ->addValue('simple', array(1,2,3))
        ->addValue('multiple', array(
            array(1,2),
            array('abc', 'def'),
            array(1.1, 1.2),
            array(true, false),
            array( new \Datetime()) ))

        ->addComment('Array of tables')

        ->addArrayTables('fruit')                            // Row
            ->addValue('name', 'apple')
            ->addArrayTables('fruit.variety')
                ->addValue('name', 'red delicious')
            ->addArrayTables('fruit.variety')
                ->addValue('name', 'granny smith')
        ->addArrayTables('fruit')                            // Row
            ->addValue('name', 'banana')
            ->addArrayTables('fruit.variety')
                ->addValue('name', 'platain')

        ->getTomlString();    // Generate the TOML string
```
The result:

    #Toml file

    [data.string]
    name = "Toml" #This is your name
    newline = "This string has a \n new line character."
    winPath = "C:\\Users\\nodejs\\templates"
    literal = '<\i\c*\s*>'
    unicode = "unicode character: δ"

    [data.bool]
    t = true
    f = false

    [data.integer]
    positive = 25 #Comment inline.
    negative = -25

    [data.float]
    positive = 25.25
    negative = -25.25

    [data.datetime]
    datetime = 2013-06-10T21:12:48Z

    #Related to arrays

    [data.array]
    simple = [1, 2, 3]
    multiple = [[1, 2], ["abc", "def"], [1.1, 1.2], [true, false], [2013-06-10T21:12:48Z]]

    # Array of tables

    [[fruit]]
        name = "apple"

        [[fruit.variety]]
            name = "red delicious"

        [[fruit.variety]]
            name = "granny smith"

    [[fruit]]
        name = "banana"

        [[fruit.variety]]
        name = "platain"

Unit tests
----------
This library requires [PHPUnit](https://phpunit.de/) >= 6.3.
You can run the unit tests with the following command:

```bash
$ cd toml
$ phpunit
```

## License

This library is open-sourced software licensed under the
[MIT license](http://opensource.org/licenses/MIT).
