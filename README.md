This TOML parser implmentation for TOM04 was created in order to build a Zephir version,
starting with the Yosymfony\Toml source.

This version is maintained at [Betrixed\
The few PHP TOML implementations I have seen all used array reference (array& $aref) 
to generate and hold place in the TOML array tree.

I chose to find out the hard way what it was like to write an extension using Zephir.
Zephir is an altered and cut-down analog of PHP language. Its parser written in PHP, and 
it generates C source code which compiles to a PHP extension, largely by doing things
with PHP objects and functions, called from C.

The PHP interpreted version of the TOML parser was written to remove the use of array references.
The Zephir version uses the same code structure and strategy, the "algorithm" is the same.
The same PHP objects and arrays are constructed from a TOML document file. 

So there is  irreducible overhead in changing the PHP interpreter machine state.
Some ballpark testing on a few TOML files, demonstrates that the interpreted version on PHP 7.2,
takes no more than 21-25% longer than the compiled Zephir extension with similar code.

Table and TableList objects
=========================

Zephir does not generate C code for Object or Array reference operators - (&). 
This implementation uses objects KeyTable, TableList, and ValueList.

All of these implement the Arrayable interface. 
Arrayable extends interfaces \ArrayAccess and \Countable.

Arrayable adds the toArray() method returns a nested PHP array representation.
Arrayable adds a generic setTag and getTag, to associate any value with the object
without having to extend the storage tree objects. The Toml parser generates some
small PartTag objects

```php
interface Arrayable extends \ArrayAccess, \Countable {
    public function setTag($any);   
    public function getTag();
    public function toArray(): array;
}
```

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
