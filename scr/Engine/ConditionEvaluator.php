<?php

declare(strict_types=1);

namespace Melchio\Engine;

/**
 * Avaliador de condições clínicas — o núcleo do motor de regras.
 *
 * Operadores suportados:
 *   Lógicos:     and, or, not
 *   Comparação:  >, >=, <, <=, ==
 *   Containment: in
 *   Existência:  exists
 *   Funções:     count (usado como operando dentro de comparações)
 *
 * Regras de resolução de valores:
 *   - Números e booleanos → retornam como estão
 *   - Objetos especiais {"count": "path"} → resolvem a função
 *   - Strings com ponto ("patient.comorbidities") → sempre resolvidas como caminho no contexto
 *   - Strings sem ponto ("bp_systolic") → se existem no contexto, retorna o valor; senão, retorna como literal
 *
 * Isso permite que "diabetes" em {"in": ["diabetes", "patient.comorbidities"]}
 * seja tratado como literal, enquanto "bp_systolic" em {">": ["bp_systolic", 180]}
 * seja resolvido do contexto.
 */
class ConditionEvaluator
{
    /**
     * Marcador interno para paths que não puderam ser resolvidos.
     */
    private const UNRESOLVED = '__MELCHIO_UNRESOLVED__';

    /**
     * Avalia uma condição contra um contexto normalizado.
     *
     * @param array|bool $condition Árvore de condições em formato JSON decodificado
     * @param array      $context   Contexto normalizado (plano)
     * @return bool
     */
    public function evaluate(array|bool $condition, array $context): bool
    {
        if (is_bool($condition)) {
            return $condition;
        }

        if (empty($condition)) {
            return true;
        }

        $operator = array_key_first($condition);

        return match ($operator) {
            'and'    => $this->evaluateAnd($condition['and'], $context),
            'or'     => $this->evaluateOr($condition['or'], $context),
            'not'    => $this->evaluateNot($condition['not'], $context),
            '>'      => $this->compare('>', $condition[$operator], $context),
            '>='     => $this->compare('>=', $condition[$operator], $context),
            '<'      => $this->compare('<', $condition[$operator], $context),
            '<='     => $this->compare('<=', $condition[$operator], $context),
            '=='     => $this->compare('==', $condition[$operator], $context),
            'in'     => $this->evaluateIn($condition['in'], $context),
            'exists' => $this->evaluateExists($condition['exists'], $context),
            default  => throw new \RuntimeException("Operador desconhecido: {$operator}"),
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Operadores lógicos
    // ══════════════════════════════════════════════════════════════════════════

    private function evaluateAnd(array $conditions, array $context): bool
    {
        foreach ($conditions as $cond) {
            if (!$this->evaluate($cond, $context)) {
                return false; // short-circuit
            }
        }
        return true;
    }

    private function evaluateOr(array $conditions, array $context): bool
    {
        foreach ($conditions as $cond) {
            if ($this->evaluate($cond, $context)) {
                return true; // short-circuit
            }
        }
        return false;
    }

    private function evaluateNot(mixed $condition, array $context): bool
    {
        return !$this->evaluate($condition, $context);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Comparação
    // ══════════════════════════════════════════════════════════════════════════

    private function compare(string $op, array $operands, array $context): bool
    {
        $left  = $this->resolveValue($operands[0], $context);
        $right = $this->resolveValue($operands[1], $context);

        // Se qualquer operando não pôde ser resolvido, a comparação é falsa
        if ($left === self::UNRESOLVED || $right === self::UNRESOLVED) {
            return false;
        }

        return match ($op) {
            '>'  => $left > $right,
            '>=' => $left >= $right,
            '<'  => $left < $right,
            '<=' => $left <= $right,
            '==' => $left == $right,
            default => false,
        };
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Containment (in)
    // ══════════════════════════════════════════════════════════════════════════

    private function evaluateIn(array $operands, array $context): bool
    {
        $needle   = $this->resolveValue($operands[0], $context);
        $haystack = $this->resolveValue($operands[1], $context);

        if ($haystack === self::UNRESOLVED || !is_array($haystack)) {
            return false;
        }

        return in_array($needle, $haystack, true);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Existência (exists)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Verifica se um caminho existe no contexto.
     * NÃO usa resolveValue — faz verificação direta de existência de chave.
     */
    private function evaluateExists(string $path, array $context): bool
    {
        if (str_contains($path, '.')) {
            return $this->pathExistsInContext($path, $context);
        }

        return array_key_exists($path, $context);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Resolução de valores
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Resolve um valor a partir do contexto ou retorna como literal.
     */
    private function resolveValue(mixed $value, array $context): mixed
    {
        // Números, booleanos, null → devolver direto
        if (!is_string($value) && !is_array($value)) {
            return $value;
        }

        // Objetos especiais: {"count": "path"}
        if (is_array($value)) {
            return $this->resolveSpecialFunction($value, $context);
        }

        // String com ponto → sempre resolver como caminho
        if (str_contains($value, '.')) {
            return $this->resolvePath($value, $context);
        }

        // String sem ponto → se existe como chave top-level no contexto, resolver
        if (array_key_exists($value, $context)) {
            return $context[$value];
        }

        // Não encontrado → tratar como literal
        return $value;
    }

    /**
     * Resolve funções especiais como {"count": "patient.comorbidities"}.
     */
    private function resolveSpecialFunction(array $obj, array $context): mixed
    {
        if (isset($obj['count'])) {
            $resolved = $this->resolveValue($obj['count'], $context);
            return is_array($resolved) ? count($resolved) : 0;
        }

        // Extensível: adicionar mais funções aqui
        // Ex: {"sum": "path"}, {"avg": "path"}, {"max": "path"}

        return $obj;
    }

    /**
     * Resolve um caminho com notação de ponto no contexto.
     * Ex: "patient.comorbidities" → $context['patient']['comorbidities']
     *
     * Retorna UNRESOLVED se qualquer parte do caminho não existir.
     */
    private function resolvePath(string $path, array $context): mixed
    {
        $keys    = explode('.', $path);
        $current = $context;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return self::UNRESOLVED;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Verifica se um caminho existe no contexto (para o operador exists).
     */
    private function pathExistsInContext(string $path, array $context): bool
    {
        $keys    = explode('.', $path);
        $current = $context;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }
}