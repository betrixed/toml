<?php

/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Toml;

use Yosymfony\ParserUtils\AbstractParser;
use Yosymfony\ParserUtils\Token;
use Yosymfony\ParserUtils\TokenStream;
use Yosymfony\ParserUtils\SyntaxErrorException;
use Yosymfony\Toml\Lexer;
/**
 * Parser for TOML strings (specification version 0.4.0).
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Parser extends AbstractParser
{

    private $keys = [];
    private $keyOfTables = [];
    private $keysOfImplicitArrayOfTables = [];
    private $arrayOfTablekeyCounters = [];
    private $currentKeyPrefix = '';
    private $result = [];
    private $workArray;
    private static $tokensNotAllowedInBasicStrings = [
        Lexer::T_ESCAPE,
        Lexer::T_NEWLINE,
        Lexer::T_EOS,
    ];
    private static $tokensNotAllowedInLiteralStrings = [
        Lexer::T_NEWLINE,
        Lexer::T_EOS,
    ];

    /**
     * {@inheritdoc}
     */
    public function parse(string $input)
    {
        if (preg_match('//u', $input) === false) {
            throw new SyntaxErrorException('The TOML input does not appear to be valid UTF-8.');
        }

        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $input = str_replace("\t", ' ', $input);

        return parent::parse($input);
    }

    /**
     * {@inheritdoc}
     */
    protected function parseImplentation(TokenStream $ts): array
    {
        $this->resetWorkArrayToResultArray();

        while ($ts->hasPendingTokens()) {
            $this->processExpression($ts);
        }

        return $this->result;
    }

    /**
     * Process an expression
     *
     * @param TokenStream $ts The token stream
     */
    private function processExpression(TokenStream $ts): void
    {
        $tokenName = $ts->peekNext();
        // switch vs elseif -- about the same, unless repeated function
        // calls are being made on each if.
        switch ($tokenName) {
            case Lexer::T_HASH :
                 $this->parseComment($ts);
                break;
            case Lexer::T_QUOTATION_MARK:
            case Lexer::T_UNQUOTED_KEY:
            case Lexer::T_APOSTROPHE :
            case Lexer::T_INTEGER :
                $this->parseKeyValue($ts);
                break;
            case Lexer::T_LEFT_SQUARE_BRAKET:
                if ($ts->isNextSequence([Lexer::T_LEFT_SQUARE_BRAKET,Lexer::T_LEFT_SQUARE_BRAKET])) {
                    $this->parseArrayOfTables($ts);
                }
                else {
                    $this->parseTable($ts);
                }
                break;
            case Lexer::T_SPACE : 
            case Lexer::T_NEWLINE:
            case Lexer::T_EOS:
                $ts->moveNext();
                break;
            default:
                //TODO: This message is probably outdated by now
                // Not general enougy, probably to match test cases.
                $msg = 'Expected T_HASH or T_UNQUOTED_KEY.';
                $this->unexpectedTokenError($ts->moveNext(), $msg);
                break;
        }
    }

    private function parseComment(TokenStream $ts): void
    {
        $this->matchNext(Lexer::T_HASH, $ts);

        while (!$ts->isNextAny([Lexer::T_NEWLINE, Lexer::T_EOS])) {
            $ts->moveNext();
        }
    }

    private function skipIfSpace(TokenStream $ts) : int {
        return $ts->skipWhile(Lexer::T_SPACE);
    }
    
    private function parseKeyValue(TokenStream $ts, bool $isFromInlineTable = false): void
    {
        $keyName = $this->parseKeyName($ts);
        $this->addKeyToKeyStore($this->composeKeyWithCurrentKeyPrefix($keyName));
        $this->skipIfSpace($ts);
        $this->matchNext(Lexer::T_EQUAL, $ts);
        $this->skipIfSpace($ts);

        if ($ts->isNext(Lexer::T_LEFT_SQUARE_BRAKET)) {
            $this->workArray[$keyName] = $this->parseArray($ts);
        } elseif ($ts->isNext(Lexer::T_LEFT_CURLY_BRACE)) {
            $this->parseInlineTable($ts, $keyName);
        } else {
            $this->workArray[$keyName] = $this->parseSimpleValue($ts)->value;
        }

        if (!$isFromInlineTable) {
            $this->skipIfSpace($ts);
            $this->parseCommentIfExists($ts);
            $this->errorIfNextIsNotNewlineOrEOS($ts);
        }
    }

    private function parseKeyName(TokenStream $ts): string
    {
        // Too much data hiding can lead to unneccessary function calls
        // we want to match a token name, but the stream interface
        // only offers isNext - which does a lot of fussing around
        // with moveNext, then moving the index back again.
        // To do this one only at a stream location, I would
        // like a peekNext that returns the next token name,
        // or an empty string if none 

        $token = $ts->peekNext();
        switch ($token) {
            case Lexer::T_UNQUOTED_KEY:
                return $this->matchNext(Lexer::T_UNQUOTED_KEY, $ts);
            case Lexer::T_QUOTATION_MARK:
                return $this->parseBasicString($ts);
            case Lexer::T_APOSTROPHE:
                return $this->parseLiteralString($ts);
            case Lexer::T_INTEGER :
                return (string) $this->parseInteger($ts);
            default:
                $msg = 'Unexpected token in parseKeyName';
                $this->unexpectedTokenError($ts->moveNext(), $msg);
                break;
        }
    }

    /**
     * @return object An object with two public properties: value and type.
     */
    private function parseSimpleValue(TokenStream $ts)
    {
        $token = $ts->peekNext();
        switch ($token) {
            case Lexer::T_BOOLEAN:
                $type = 'boolean';
                $value = $this->parseBoolean($ts);
                break;
            case Lexer::T_INTEGER:
                $type = 'integer';
                $value = $this->parseInteger($ts);
                break;
            case Lexer::T_FLOAT:
                $type = 'float';
                $value = $this->parseFloat($ts);
                break;
            case Lexer::T_QUOTATION_MARK:
                $type = 'string';
                $value = $this->parseBasicString($ts);
                break;
            case Lexer::T_3_QUOTATION_MARK:
                $type = 'string';
                $value = $this->parseMultilineBasicString($ts);
                break;
            case Lexer::T_APOSTROPHE:
                $type = 'string';
                $value = $this->parseLiteralString($ts);
                break;
            case Lexer::T_3_APOSTROPHE:
                $type = 'string';
                $value = $this->parseMultilineLiteralString($ts);
                break;
            case Lexer::T_DATE_TIME:
                $type = 'datetime';
                $value = $this->parseDatetime($ts);
                break;
            default:
                $this->unexpectedTokenError(
                        $ts->moveNext(), 'Expected boolean, integer, long, string or datetime.'
                );
                break;
        }


        $valueStruct = new class() {

            public $value;
            public $type;
        };

        $valueStruct->value = $value;
        $valueStruct->type = $type;

        return $valueStruct;
    }

    private function parseBoolean(TokenStream $ts): bool
    {
        return $this->matchNext(Lexer::T_BOOLEAN, $ts) == 'true' ? true : false;
    }

    private function parseInteger(TokenStream $ts): int
    {
        $token = $ts->moveNext();
        $value = $token->getValue();

        if (preg_match('/([^\d]_[^\d])|(_$)/', $value)) {
            $this->syntaxError(
                    'Invalid integer number: underscore must be surrounded by at least one digit.', $token
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                    'Invalid integer number: leading zeros are not allowed.', $token
            );
        }

        return (int) $value;
    }

    private function parseFloat(TokenStream $ts): float
    {
        $token = $ts->moveNext();
        $value = $token->getValue();

        if (preg_match('/([^\d]_[^\d])|_[eE]|[eE]_|(_$)/', $value)) {
            $this->syntaxError(
                    'Invalid float number: underscore must be surrounded by at least one digit.', $token
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                    'Invalid float number: leading zeros are not allowed.', $token
            );
        }

        return (float) $value;
    }

    private function parseBasicString(TokenStream $ts): string
    {
        $this->matchNext(Lexer::T_QUOTATION_MARK, $ts);

        $result = '';

        while (!$ts->isNext(Lexer::T_QUOTATION_MARK)) {
            if ($ts->isNextAny(self::$tokensNotAllowedInBasicStrings)) {
                $this->unexpectedTokenError($ts->moveNext(), 'This character is not valid.');
            }

            $value = $ts->isNext(Lexer::T_ESCAPED_CHARACTER) ? $this->parseEscapedCharacter($ts)
                        : $ts->moveNext()->getValue();
            $result .= $value;
        }

        $this->matchNext(Lexer::T_QUOTATION_MARK, $ts);

        return $result;
    }

    private function parseMultilineBasicString(TokenStream $ts): string
    {
        $this->matchNext(Lexer::T_3_QUOTATION_MARK, $ts);

        $result = '';

        if ($ts->isNext(Lexer::T_NEWLINE)) {
            $ts->moveNext();
        }
        while(true) {
            $nextToken = $ts->peekNext();
            switch($nextToken) {
                case Lexer::T_3_QUOTATION_MARK :
                    $this->matchNext(Lexer::T_3_QUOTATION_MARK, $ts);
                    break 2;
                case Lexer::T_EOS:
                  $this->unexpectedTokenError($ts->moveNext(), 'Expected token "T_3_QUOTATION_MARK".');
                  break;
                case Lexer::T_ESCAPE:
                    $ts->skipWhileAny([Lexer::T_ESCAPE,Lexer::T_SPACE,Lexer::T_NEWLINE]);
                    break;
                case Lexer::T_SPACE:
                    $result .= ' ';
                    $ts->moveNext();
                    break;
                case Lexer::T_NEWLINE:
                    $result .= PHP_EOL;
                    $ts->moveNext();
                    break;
                case Lexer::T_ESCAPED_CHARACTER:
                    $value = $this->parseEscapedCharacter($ts);
                    $result .= $value;
                    break;
                default:
                    $value = $ts->moveNext()->getValue();
                    $result .= $value;
                    break;
            }
        }
        return $result;
    }

    private function parseLiteralString(TokenStream $ts): string
    {
        $this->matchNext(Lexer::T_APOSTROPHE, $ts);

        $result = '';

        while (!$ts->isNext(Lexer::T_APOSTROPHE)) {
            if ($ts->isNextAny(self::$tokensNotAllowedInLiteralStrings)) {
                $this->unexpectedTokenError($ts->moveNext(), 'This character is not valid.');
            }

            $result .= $ts->moveNext()->getValue();
        }

        $this->matchNext(Lexer::T_APOSTROPHE, $ts);

        return $result;
    }

    private function parseMultilineLiteralString(TokenStream $ts): string
    {
        $this->matchNext(Lexer::T_3_APOSTROPHE, $ts);

        $result = '';

        if ($ts->isNext(Lexer::T_NEWLINE)) {
            $ts->moveNext();
        }

        while (!$ts->isNext(Lexer::T_3_APOSTROPHE)) {
            if ($ts->isNext(Lexer::T_EOS)) {
                $this->unexpectedTokenError($ts->moveNext(), 'Expected token "T_3_APOSTROPHE".');
            }

            $result .= $ts->moveNext()->getValue();
        }

        $this->matchNext(Lexer::T_3_APOSTROPHE, $ts);

        return $result;
    }

    private function parseEscapedCharacter(TokenStream $ts): string
    {
        $token = $ts->moveNext();
        $value = $token->getValue();

        switch ($value) {
            case '\b':
                return "\b";
            case '\t':
                return "\t";
            case '\n':
                return "\n";
            case '\f':
                return "\f";
            case '\r':
                return "\r";
            case '\"':
                return '"';
            case '\\\\':
                return '\\';
        }

        if (strlen($value) === 6) {
            return json_decode('"' . $value . '"');
        }

        preg_match('/\\\U([0-9a-fA-F]{4})([0-9a-fA-F]{4})/', $value, $matches);

        return json_decode('"\u' . $matches[1] . '\u' . $matches[2] . '"');
    }

    private function parseDatetime(TokenStream $ts): \Datetime
    {
        $date = $this->matchNext(Lexer::T_DATE_TIME, $ts);

        return new \Datetime($date);
    }

    private function parseArray(TokenStream $ts): array
    {
        $result = [];
        $leaderType = '';

        $this->matchNext(Lexer::T_LEFT_SQUARE_BRAKET, $ts);

        while (!$ts->isNext(Lexer::T_RIGHT_SQUARE_BRAKET)) {
            $ts->skipWhileAny([Lexer::T_NEWLINE, Lexer::T_SPACE]);
            $this->parseCommentsInsideBlockIfExists($ts);

            if ($ts->isNext(Lexer::T_LEFT_SQUARE_BRAKET)) {
                if ($leaderType === '') {
                    $leaderType = 'array';
                }

                if ($leaderType !== 'array') {
                    $this->syntaxError(sprintf(
                                    'Data types cannot be mixed in an array. Value: "%s".', $valueStruct->value
                    ));
                }

                $result[] = $this->parseArray($ts);
            } else {
                $valueStruct = $this->parseSimpleValue($ts);

                if ($leaderType === '') {
                    $leaderType = $valueStruct->type;
                }

                if ($valueStruct->type !== $leaderType) {
                    $this->syntaxError(sprintf(
                                    'Data types cannot be mixed in an array. Value: "%s".', $valueStruct->value
                    ));
                }

                $result[] = $valueStruct->value;
            }

            $ts->skipWhileAny([Lexer::T_NEWLINE, Lexer::T_SPACE]);
            $this->parseCommentsInsideBlockIfExists($ts);

            if (!$ts->isNext(Lexer::T_RIGHT_SQUARE_BRAKET)) {
                $this->matchNext(Lexer::T_COMMA, $ts);
            }

            $ts->skipWhileAny([Lexer::T_NEWLINE, Lexer::T_SPACE]);
            $this->parseCommentsInsideBlockIfExists($ts);
        }

        $this->matchNext(Lexer::T_RIGHT_SQUARE_BRAKET, $ts);

        return $result;
    }

    private function parseInlineTable(TokenStream $ts, string $keyName): void
    {
        $this->matchNext(Lexer::T_LEFT_CURLY_BRACE, $ts);
        $priorcurrentKeyPrefix = $this->currentKeyPrefix;
        $priorWorkArray = &$this->workArray;

        $this->addArrayKeyToWorkArray($keyName);
        $this->currentKeyPrefix = $this->composeKeyWithCurrentKeyPrefix($keyName);

        $this->parseSpaceIfExists($ts);

        if ($ts->peekNext() !== Lexer::T_RIGHT_CURLY_BRACE) {
            $this->parseKeyValue($ts, true);
            $this->parseSpaceIfExists($ts);
        }

        while ($ts->peekNext() === Lexer::T_COMMA) {
            $ts->moveNext();

            $this->parseSpaceIfExists($ts);
            $this->parseKeyValue($ts, true);
            $this->parseSpaceIfExists($ts);
        }

        $this->matchNext(Lexer::T_RIGHT_CURLY_BRACE, $ts);
        $this->currentKeyPrefix = $priorcurrentKeyPrefix;
        $this->workArray = &$priorWorkArray;
    }

    private function parseTable(TokenStream $ts): void
    {
        $this->matchNext(Lexer::T_LEFT_SQUARE_BRAKET, $ts);

        $fullTableName = $key = $this->parseKeyName($ts);

        $this->resetWorkArrayToResultArray();
        $this->addArrayKeyToWorkArray($key);

        while ($ts->isNext(Lexer::T_DOT)) {
            $ts->moveNext();

            $key = $this->parseKeyName($ts);
            $fullTableName .= ".$key";

            $this->addArrayKeyToWorkArray($key);
        }

        $this->addKeyToTableKeyStore($this->composeKeyWithCurrentKeyPrefix($fullTableName));
        $this->currentKeyPrefix = $fullTableName;
        $this->matchNext(Lexer::T_RIGHT_SQUARE_BRAKET, $ts);

        $this->parseSpaceIfExists($ts);
        $this->parseCommentIfExists($ts);
        $this->errorIfNextIsNotNewlineOrEOS($ts);
    }

    private function parseArrayOfTables(TokenStream $ts): void
    {
        $this->matchNext(Lexer::T_LEFT_SQUARE_BRAKET, $ts);
        $this->matchNext(Lexer::T_LEFT_SQUARE_BRAKET, $ts);

        $fullTableName = $key = $this->parseKeyName($ts);

        $this->resetWorkArrayToResultArray();
        $this->addArrayOfTableKeyToWorkArray($key, !$ts->isNext(Lexer::T_DOT));

        while ($ts->isNext(Lexer::T_DOT)) {
            $ts->moveNext();

            $key = $this->parseKeyName($ts);
            $fullTableName .= ".$key";

            $this->addArrayOfTableKeyToWorkArray($key, !$ts->isNext(Lexer::T_DOT));
        }

        $this->addArrayOfTableKeyToKeyStore($fullTableName);
        $this->currentKeyPrefix = $fullTableName . $this->getCounterArrayOfTableKey($fullTableName);

        $this->matchNext(Lexer::T_RIGHT_SQUARE_BRAKET, $ts);
        $this->matchNext(Lexer::T_RIGHT_SQUARE_BRAKET, $ts);

        $this->parseSpaceIfExists($ts);
        $this->parseCommentIfExists($ts);
        $this->errorIfNextIsNotNewlineOrEOS($ts);
    }

    private function matchNext(int $tokenId, TokenStream $ts): string
    {
        if (!$ts->isNext($tokenId)) {
            $name = Lexer::tokenName($tokenId);
            $this->unexpectedTokenError($ts->moveNext(), "Expected \"$name\".");
        }

        return $ts->moveNext()->getValue();
    }

    private function parseSpaceIfExists(TokenStream $ts): void
    {
        if ($ts->peekNext() === Lexer::T_SPACE) {
            $ts->moveNext();
        }
    }

    private function parseCommentIfExists(TokenStream $ts): void
    {
        if ($ts->isNext(Lexer::T_HASH)) {
            $this->parseComment($ts);
        }
    }

    private function parseCommentsInsideBlockIfExists(TokenStream $ts): void
    {
        $this->parseCommentIfExists($ts);

        while ($ts->isNext(Lexer::T_NEWLINE)) {
            $ts->moveNext();
            $ts->skipWhile(Lexer::T_SPACE);
            $this->parseCommentIfExists($ts);
        }
    }

    private function addKeyToKeyStore(string $keyName): void
    {
        if (in_array($keyName, $this->keys, true) === true) {
            $this->syntaxError(sprintf(
                            'The key "%s" has already been defined previously.', $keyName
            ));
        }

        $this->keys[] = $keyName;
    }

    private function addKeyToTableKeyStore(string $keyName): void
    {
        $this->addKeyToKeyStore($keyName);
        $this->keyOfTables[] = $keyName;
    }

    private function addArrayOfTableKeyToKeyStore(string $keyName): void
    {
        if (isset($this->arrayOfTablekeyCounters[$keyName]) === false) {
            $this->addKeyToKeyStore($keyName);
        }

        $keyNameParts = explode('.', $keyName);

        if ($this->isNecesaryToProcessImplicitKeyNameParts($keyNameParts)) {
            array_pop($keyNameParts);

            foreach ($keyNameParts as $keyNamePart) {
                $this->keysOfImplicitArrayOfTables[] = implode('.', $keyNameParts);
                array_pop($keyNameParts);
            }

            return;
        }

        if (in_array($keyName, $this->keysOfImplicitArrayOfTables) === true && isset($this->arrayOfTablekeyCounters[$keyName])
                === false) {
            $this->syntaxError(
                    sprintf('The array of tables "%s" has already been defined as previous table', $keyName)
            );
        }
    }

    private function isNecesaryToProcessImplicitKeyNameParts(array $keynameParts): bool
    {
        if (count($keynameParts) > 1) {
            array_pop($keynameParts);
            $implicitArrayOfTablesName = implode('.', $keynameParts);

            if (in_array($implicitArrayOfTablesName, $this->arrayOfTablekeyCounters)
                    === false) {
                return true;
            }
        }

        return false;
    }

    private function getCounterArrayOfTableKey($keyName): int
    {
        if (isset($this->arrayOfTablekeyCounters[$keyName]) === false) {
            return $this->arrayOfTablekeyCounters[$keyName] = 0;
        }

        return $this->arrayOfTablekeyCounters[$keyName] = $this->arrayOfTablekeyCounters[$keyName] + 1;
    }

    private function composeKeyWithCurrentKeyPrefix(string $keyName): string
    {
        $composedKey = $this->currentKeyPrefix;

        if ($composedKey !== '') {
            $composedKey .= '.';
        }

        $composedKey .= $keyName;

        return $composedKey;
    }

    private function addArrayKeyToWorkArray(string $keyName): void
    {
        if (isset($this->workArray[$keyName]) === false) {
            $this->workArray[$keyName] = [];
        }

        $this->workArray = &$this->workArray[$keyName];
    }

    private function addArrayOfTableKeyToWorkArray(string $keyName, bool $islast): void
    {
        if (isset($this->workArray[$keyName]) === false) {
            $this->workArray[$keyName] = [];
            $this->workArray[$keyName][] = [];
        } elseif ($islast) {
            $this->workArray[$keyName][] = [];
        }

        if (in_array($keyName, $this->keyOfTables) === false) {
            end($this->workArray[$keyName]);
            $this->workArray = &$this->workArray[$keyName][key($this->workArray[$keyName])];

            return;
        }

        $this->workArray = &$this->workArray[$keyName];
    }

    private function resetWorkArrayToResultArray(): void
    {
        $this->currentKeyPrefix = '';
        $this->workArray = &$this->result;
    }

    private function errorIfNextIsNotNewlineOrEOS(TokenStream $ts): void
    {
        if (!$ts->isNextAny([Lexer::T_NEWLINE, Lexer::T_EOS])) {
            $this->unexpectedTokenError($ts->moveNext(), 'Expected T_NEWLINE or T_EOS.');
        }
    }

    private function unexpectedTokenError(Token $token, string $expectedMsg): void
    {
        $name = Lexer::tokenName($token->getId());
        $line = $token->getLine();
        $value = $token->getValue();
        $msg = sprintf('Syntax error: unexpected token "%s" at line %s with value "%s".', $name, $line, $value);

        if (!empty($expectedMsg)) {
            $msg = $msg . ' ' . $expectedMsg;
        }

        throw new SyntaxErrorException($msg);
    }

    private function syntaxError($msg, Token $token = null): void
    {
        if ($token !== null) {
            $name = Lexer::tokenName($token->getId());
            $line = $token->getLine();
            $value = $token->getValue();
            $tokenMsg = sprintf('Token: "%s" line: %s value "%s".', $name, $line, $value);
            $msg .= ' ' . $tokenMsg;
        }

        throw new SyntaxErrorException($msg);
    }

}
