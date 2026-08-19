<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * "JSONB_EXISTS" "(" SimpleArithmeticExpression "," SimpleArithmeticExpression ")"
 *
 * Registered in config/packages/doctrine.yaml's `orm.dql.string_functions`.
 * Maps to Postgres's `jsonb_exists(jsonb, text)` — "does this string exist
 * as a top-level array element/object key" — used to filter Exercise's
 * `primary_muscles`/`secondary_muscles` JSON array columns (setly-phase-
 * exercise-media.md §3/§5) without a native DQL array-contains operator.
 */
class JsonbExistsFunction extends FunctionNode
{
    public Node|string $jsonExpression;
    public Node|string $valueExpression;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'JSONB_EXISTS(%s::jsonb, %s)',
            $sqlWalker->walkSimpleArithmeticExpression($this->jsonExpression),
            $sqlWalker->walkSimpleArithmeticExpression($this->valueExpression),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->jsonExpression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_COMMA);

        $this->valueExpression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
