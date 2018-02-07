<?php

namespace Yosymfony\Toml;
/**
 * Description of TokenArray
 * Just enough to satisfy LexerText
 * @author Michael Rynn
 * 
 */
class TokenArray
{
    protected $tokens;
    protected $ct;
    
    protected $index = -1;
    
    /**
     * Constructor
     *
     * @param Token[] List of tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->ct = count($tokens);
    }

/**
     * Checks if the following tokens in the stream match with the sequence of tokens
     *
     * @param int[] $tokenIds Sequence of token ids
     *
     * @return bool
     */
    public function isNextSequence(array $tokenIds) : bool
    {
        $result = true;
        $currentIndex = $this->index;

        foreach ($tokenIds as $id) {
            $token = $this->moveNext();

            if ($token === null || $token->id != $id) {
                $result = false;

                break;
            }
        }

        $this->index = $currentIndex;

        return $result;
    }    
    
    /**
     * Moves the pointer one token forward
     *
     * @return Token|null The token or null if there are not more tokens
     */
    public function moveNext() : ?Token
    {
        $next = $this->index + 1;
        $this->index = $next;
        return ($next < $this->ct) ? $this->tokens[$next] : null;
    }
    
    
    public function peekNext() : int {
        $next = $this->index + 1;
        return ($next < $this->ct) ? $this->tokens[$next]->id : 0;
    }
}
