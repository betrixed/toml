<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Yosymfony\Toml;

/**
 * Description of TokenStream
 * Gets same token object each time, from the current parse point
 * Go back , and look ahead, will require array of token clones, or tokenIds,
 * @author Michael Rynn
 */
class TokenStream
{
    public $lines; // all lines
    
    public $curLine;
    public $id;
    public $value;
    public $lineNo;    // line number of curLine
    public $tokenLine;  // line number of current token
    public $isSingle; // was found in single character list

    public $offset; // unparsed offset on current line
    public $regex; // Reference to current set of tokens being searched for
    public $singles; // Reference to single character lookup for tokenId
    public $unknownId; // int value to represent single character not in singles.
    public $token;  // The one and only token instance
    // topLevel parse
    
    
    public function __construct() {
        $this->token = new Token();
    }
    public function setUnknownId(int $id)
    {
        $this->unknownId = $id;
    }
    /**
     * Argument is reference to associative array[int] of string regular expressions
     * @param array $ref
     */
    public function setExpList(array &$ref) {
        $this->regex = &$ref;
    }
    /** 
     * For lookup of tokenId of single character string
     * Argument is reference to associative array[string] of int 
     * @param array $ref
     */
    
    public function setSingles(array &$ref) {
        $this->singles = &$ref;
    }
     public function setInput(string $input)
     {
        $lines = explode("\n", $input);
        $this->setLines($lines);
     }
    /**
     * Apply a successful expression match to update token and text parse state
     * @param int $id
     * @param array $mval
     */
    private function applyMatch(int $id, array & $mval) : void {
        $this->value = $mval[1];
        $this->id = $id;
        $this->isSingle = false;
        $this->line = $this->tokenLine;
        
        $takeOff = strlen($mval[0]);
        $this->offset += $takeOff;
        $this->curLine = substr($this->curLine,$takeOff);
    }
                    
    /** Find a match amoung a list of TokenIds 
     *  Since T_NEWLINE & T_EOS are always implicit
     *  in text processing, they are always implicitly
     *  returnable as a value.
     *  Lexer is likely not to have them as a regular expression,
     *  @param array& $idList Array of token ids, except for NEWLINE & EOS
     *  @return $tokenId - what was found,
     *   parse point was moved forwards
     *  @return 0  -  no match, parse point is stuck.
     */
    public function matchNextAny(array& $idList) : int {
        foreach($idList as $id) {
            $realMatch = $this->matchNext($id);
            if ($realMatch !== 0) {
                return $realMatch;
            }
        }
        return 0; 
    }
    /** 
     * This uses the current set of regular expressions
     * @param int $id
     * @return int  Same as $id passed, or NEWLINE or EOS, or 0
     */
    public function matchNext(int $id) : int {
        if (!$this->lineAvailable()) {
            /** hopefully NEWLINE or EOS */ 
            // return whatever we got, 
            // which may or may not be in the explicit list
            return $this->id; 
        }
        $pattern = $this->regex[$id];
        if (preg_match($pattern, $this->curLine, $matches)) {
            $this->applyMatch($id, $matches);
            return $id;
        }

        return 0; 
    }
    /** 
     * $idList must not contain NEWLINE or EOS.
     * Last token id could be either, or whatever stopped the skipping.
     * @param array $idList Reference to list of token ids
     * @return int  Count of matches found in $idList
     */
    public function skipWhileAny(array& $idList) : int {
        $skip = 0;
        while (true) {
           $nextId = $this->matchNextAny($idList);
           if ($nextId !== Lexer::T_NEWLINE && $nextId !== Lexer::T_EOS) {
                $skip++; // was a text parse match
           }
           else {
               break;
           }
        } 
        return $skip;
    }
    public function hasPendingTokens() : bool {
        return ($this->id !== Lexer::T_EOS && $this->id !== Lexer::T_BAD);
    }
    public function moveNext() : Token {
        $token = $this->token;
        $token->set($this->value, $this->id, $this->tokenLine, $this->isSingle);
        $this->loadNext();
        return $token;
    }
    
    public function end() : Token {
        $token = $this->token;
        $token->set($this->value, $this->id, $this->tokenLine, $this->isSingle);
        return $token;
    }
    /**
     * Return the current token value, do next parse step
     * @return string
     */
    public function valueMove() : string {
        $value = $this->value;
        $this->loadNext();
        return $value;
    }
    /** 
     * Don't care about token, value, 
     */
    public function movePeekNext() {
        $this->loadNext();
        return $this->id;
    }
    
    public function peekNext() : int {
        return $this->id;
    }
    
    
    
    public function setLines(& $lines) {
        $this->lines = & $lines;
        $this->lineNo = 0;
        $this->offset = 0;
        $this->curLine = (count($lines) > 0) ? $lines[0] : null;
        $this->loadNext();
    }
    
    /**
     * @return true if text to parse in $this->curLine
     * @return false if sets the token values to NEWLINE or EOS
     */
    private function lineAvailable() : bool {
        if (empty($this->curLine)) {
            $nextLine = $this->lineNo + 1;
            if ($nextLine < count($this->lines)) {
               $this->curLine = $this->lines[$nextLine];
               $this->offset = 0;
               $this->value = "\n";
               $this->id = Lexer::T_NEWLINE;
               $this->lineNo = $nextLine;
            }
            else {
               $this->value = "";
               $this->id = Lexer::T_EOS;
            }
            $this->isSingle = true; // really
            return false;
        }
        elseif ($this->offset === 0) {
            $this->tokenLine = $this->lineNo + 1;
        }
        return true;
    }
    
    private function unicode() : void {
        // get next unicode character using mb_substr,
        // then chop this using substr
        $uni = mb_substr($this->curLine,0,1);
        $takeoff = strlen($uni);
        $this->offset += $takeoff; 
        $this->curLine = substr($this->curLine,$takeoff);
        $this->value = $uni;
        
        // There are a lot of single character lexer Ids, so just look
        // them up in a table. If its not there, it is the all purpose 'T_CHAR'
        
        $this->id = $this->singles[$uni] ?? $this->unknownId;
        
        $this->isSingle = ($this->id !== $this->unknownId);
        
    }
    private function loadNext() {
        if (!$this->lineAvailable())
            return; // may be available next call
        foreach ($this->regex as $id => $pattern) {
            if (preg_match($pattern, $this->curLine, $matches)) {
                $this->applyMatch($id, $matches);
                return;
            }
        }
        // no expressions matched, as a default, classify unicode character
        $this->unicode();

        return;
    }
}
