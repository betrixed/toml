<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Toml;


/**
 * Description of TokenStream
 * Gets same token object each time, from the current parse point
 * Go back , and look ahead, will require array of token clones, or tokenIds,
 * @author Michael Rynn
 */
class TokenStream
{

    protected $lines; // all lines
    protected $lineCount;
    protected $curLine;
    protected $id;
    protected $value;
    protected $lineNo;    // line number of curLine
    protected $tokenLine;  // line number of current token
    protected $isSingle; // was found in single character list
    protected $offset; // unparsed offset on current line
    protected $regex; // Object of type KeyTable to current set of tokens being searched for
    protected $singles; // Reference to single character lookup for tokenId
    protected $unknownId; // int value to represent single character not in singles.
    protected $newLineId; //
    protected $eosId;
    protected $token;  // The one and only token instance

    // topLevel parse

    public function __construct()
    {
        $this->token = new Token();
    }

    /**
     * Token id to be returned if no more text is available
     * @param int $id
     */
    public function setEOSId(int $id)
    {
        $this->eosId = $id;
    }
    /**
     * Token id to be returned if newline character is parsed
     * @param int $id
     */
    public function setNewLineId(int $id)
    {
        $this->newLineId = $id;
    }

    /** The unknown id is returned if no match in 
     *  the Singles character table.
     * @param int $id
     */
    public function setUnknownId(int $id)
    {
        $this->unknownId = $id;
    }

    /**
     * Argument is reference to associative array[int] of string regular expressions
     * @param array $ref
     */
    public function setExpList(KeyTable $ref)
    {
        $this->regex = $ref;
    }

    /**
     * Return current expression set object
     */
    public function getExpMap() {
        return $this->regex;
    }
    /**
     * For lookup of tokenId of single character string
     * Argument is reference to associative array[string] of int 
     * @param array $ref
     */
    public function setSingles(KeyTable $ref)
    {
        $this->singles = $ref;
    }

    public function setInput(string $input)
    {
        $boxme = new Box(explode("\n", $input));
        $this->setLines($boxme);
    }

    public function hasPendingTokens(): bool
    {
        return ($this->id !== $this->eosId);
    }

    /**
     * Get all token details of parse step
     * @return \Toml\Token
     */
    public function getToken(): Token
    {
        $token = $this->token;
        $token->set($this->value, $this->id, $this->tokenLine, $this->isSingle);
        return $token;
    }

    /**
     * Return line associated with current token
     */
    public function getLine() : int {
        return $this->tokenLine;
    }
    /**
     * Return the current parse step value
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /** 
     * Advance the parse then return the internal token id.
     */
    public function moveNextId(): int
    {
        return $this->parseNextId($this->regex);
    }
    /** 
     * Fetch internal token id.
     */
    public function getTokenId(): int
    {
        return $this->id;
    }
    /** 
      * This sets the parse state to before the first line.
      * To get the first token, call moveNextId
      */
    public function setLines(Box $lines)
    {
        $this->lines = $lines;
        $this->lineNo = 0;
        $this->offset = 0;
        $this->lineCount = count($lines->_me);
        $this->curLine = ($this->lineCount > 0) ? $lines->_me[0] : null;
    }
    
    /** Return the single character at the front of the parse. 
     *  Does not alter this objects internal state values,
     *  except for properties of Token, which must be treated as read-only. 
     *  Maybe can use this to 'predict' the next expression set to use.
     *  Return "value" and single character TokenId in the Token.
     *  Cannot return tokenId for multi-character regular expressions,
     *  which is the whole idea.
     */
    public function peekToken() : Token {
        // Put next characters in $test, not altering TokenStream state.
        $test = $this->curLine;
        $token = $this->token;
        if (empty($test)) {
            $nextLine = $this->lineNo + 1;
            $token->line = $nextLine;
            $token->isSingle = true;
            if ($nextLine < $this->lineCount) {
                $token->value = "";
                $token->id = $this->newLineId;  
            }
            else {
                $token->value = "";
                $token->id = $this->eosId;
            }
            return $token;
        }
        $uni = mb_substr($test, 0, 1); // possible to be multi-byte character
        $token->id = $this->singles->_store[$uni] ?? $this->unknownId;
        $token->isSingle = ($this->id !== $this->unknownId);
        $token->value = $uni;
        $token->line = $this->tokenLine;
        return $token;
    }
    
    /**
     * Try regular expression, and return capture, if any.
     * Assumes previous peekToken returned a known token.
     * Leaves tokenId as unknownId
     * @param string $regex
     */
    public function moveRegex(string $pattern) : string {
        $test = $this->curLine;
        $matches = null;
        if (preg_match($pattern, $test, $matches)) {
            $this->value = $matches[1];
            $this->isSingle = false;
            $this->id = $this->unknownId;
            $this->line = $this->tokenLine;
            $takeOff = strlen($matches[0]);
            $this->offset += $takeOff;
            $this->curLine = substr($test, $takeOff);
            return $this->value;
        }   
        return "";
    }
    /**
     * If a peekNextChar has been done, this uses internal Token values to 
     * advance the parse (namely the string length of the value),
     * on the current line. It is important that token values have not been altered,
     * and parse position has not been altered prior to calling this method.
     * Returns the Id of the Token, as if returned from moveNextId.
     * A call to getToken, will still return same values as the Token;
     */
    public function acceptToken() : int {
        $token = $this->token;
        if ($token->id === $this->eosId) {
            $this->value = "";
            $this->id = $this->eosId;
            return $this->id;
        }
        elseif ($token->id === $this->newLineId) {
            // do the next line
            $nextLine = $this->lineNo + 1;
            $this->curLine = $this->lines->_me[$nextLine];
            $this->offset = 0;
            $this->value = "";
            $this->id = $this->newLineId;
            $this->lineNo = $nextLine;
            return $this->id;
        }
        if ($this->offset === 0) {
            $this->tokenLine = $this->lineNo + 1;
        }
        $takeoff = strlen($token->value);
        $this->curLine = substr($this->curLine, $takeoff);
        $this->offset += $takeoff;
        $this->id = $token->id;
        $this->isSingle = $token->isSingle;
        $this->value = $token->value;
        return $this->id;
    }
    /**
     * Set up the internal current token values, from the current parse
     * position in the line, and move the parse position to the next. Return
     * a token id.
     * Returned token id may be a NEWLINE or EOS, before the
     * $patterns are checked. If neither NEWLINE, EOS, or any of the 
     * $patterns match, the next unicode character is checked against the
     * assigned Singles table, and its token id is returned, or else
     * the character value is assigned the UnknownId
     * @param \Toml\KeyTable $patterns
     * @return int
     */
    public function parseNextId(KeyTable $patterns) : int
    {
        if (empty($this->curLine)) {
            $nextLine = $this->lineNo + 1;
            if ($nextLine < $this->lineCount) {
                $this->curLine = $this->lines->_me[$nextLine];
                $this->offset = 0;
                $this->value = "";
                $this->id = $this->newLineId;
                $this->lineNo = $nextLine;
            } else {
                $this->value = "";
                $this->id = $this->eosId;
            }
            $this->isSingle = true; // really
            return $this->id;
        } elseif ($this->offset === 0) {
            $this->tokenLine = $this->lineNo + 1;
        }
        $test = $this->curLine;
        $this->curLine = null;
        foreach ($patterns->_store as $id => $pattern) {
            $matches = null;
            if (preg_match($pattern, $test, $matches)) {
                $this->id = $id;
                $this->value = $matches[1];
                $this->isSingle = false;
                $this->line = $this->tokenLine;
                $takeOff = strlen($matches[0]);
                $this->offset += $takeOff;
                $this->curLine = substr($test, $takeOff);
                return $this->id;
            }
        }
        // no expressions matched, as a default, classify unicode character
        $uni = mb_substr($test, 0, 1);
        $takeoff = strlen($uni);
        $this->offset += $takeoff;
        $this->curLine = substr($test, $takeoff);
        $this->value = $uni;

        // There are a lot of single character lexer Ids, so just look
        // them up in a table. If its not there, it is the all purpose 'T_CHAR'

        $this->id = $this->singles->_store[$uni] ?? $this->unknownId;
        $this->isSingle = ($this->id !== $this->unknownId);
        return $this->id;
    }

}