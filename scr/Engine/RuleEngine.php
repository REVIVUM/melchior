<?php

declare(strict_types=1);

namespace Melchio\Engine;

/**
 * Motor de regras clínicas.
 *
 * Carrega regras de um arquivo JSON, normaliza o contexto de entrada,
 * avalia todas as regras ordenadas por prioridade e retorna o resultado
 * estruturado com alertas, recomendações e resumo.
 */
class RuleEngine
{
    /** @var array Regras carregadas e ordenadas por prioridade */
    private array $rules = [];

    private ConditionEvaluator $evaluator;
    private ContextNormalizer  $normalizer;

    /**
     * Ordem de severidade (menor = mais grave).
     */
    private const SEVERITY_ORDER = [
        'critical' => 1,
        'high'     => 2,
        'medium'   => 3,
        'low'      => 4,
        'info'     => 5,
    ];

    public function __construct(string $rulesPath)
    {
        $this->evaluator  = new ConditionEvaluator();
        $this->normalizer = new ContextNormalizer();
        $this->loadRules($rulesPath);
    }

    /**
     * Carrega e ordena regras do arquivo JSON.
     */
    private function loadRules(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Arquivo de regras não encontrado: {$path}");
        }

        $content = file_get_contents($path);
        $rules   = json_decode($content, true);

        if (!is_array($rules)) {
            throw new \RuntimeException("Erro ao decodificar regras JSON de: {$path}");
        }

        // Ordenar por prioridade (menor número = maior prioridade)
        usort($rules, static function (array $a, array $b): int {
            return ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99);
        });

        $this->rules = $rules;
    }

    /**
     * Avalia os dados de um paciente contra todas as regras carregadas.
     *
     * @param array $input Payload completo (patient, observations, risks_noted)
     * @return array Resultado estruturado da avaliação
     */
    public function evaluate(array $input): array
    {
        // 1. Normalizar contexto
        $context = $this->normalizer->normalize($input);

        // 2. Avaliar cada regra
        $triggered = [];
        $errors    = [];

        foreach ($this->rules as $rule) {
            $ruleName = $rule['name'] ?? 'unnamed';

            try {
                $matched = $this->evaluator->evaluate($rule['condition'], $context);

                if ($matched) {
                    $triggered[] = [
                        'name'     => $ruleName,
                        'priority' => $rule['priority'] ?? null,
                        'actions'  => $rule['actions'] ?? [],
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'rule'    => $ruleName,
                    'message' => $e->getMessage(),
                ];
                error_log("[Melchio] Erro ao avaliar regra '{$ruleName}': {$e->getMessage()}");
            }
        }

        // 3. Separar alertas e recomendações
        $alerts          = [];
        $recommendations = [];

        foreach ($triggered as $rule) {
            foreach ($rule['actions'] as $action) {
                $entry = $action;
                $entry['rule'] = $rule['name'];

                if (($action['type'] ?? '') === 'alert') {
                    $alerts[] = $entry;
                } elseif (($action['type'] ?? '') === 'recommendation') {
                    $recommendations[] = $entry;
                }
            }
        }

        // 4. Determinar severidade mais alta
        $highestSeverity = $this->resolveHighestSeverity($alerts);

        // 5. Montar resposta
        $response = [
            'patient_id'       => $input['patient']['id'] ?? null,
            'evaluated_at'     => gmdate('c'),
            'triggered_rules'  => $triggered,
            'alerts'           => $alerts,
            'recommendations'  => $recommendations,
            'summary'          => [
                'total_rules_evaluated' => count($this->rules),
                'total_rules_triggered' => count($triggered),
                'highest_severity'      => $highestSeverity,
            ],
        ];

        // Incluir erros apenas se houver
        if (!empty($errors)) {
            $response['evaluation_errors'] = $errors;
        }

        return $response;
    }

    /**
     * Retorna a lista de regras carregadas.
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Determina a severidade mais alta entre os alertas.
     */
    private function resolveHighestSeverity(array $alerts): string
    {
        $best     = 'none';
        $bestRank = PHP_INT_MAX;

        foreach ($alerts as $alert) {
            $sev  = $alert['severity'] ?? 'info';
            $rank = self::SEVERITY_ORDER[$sev] ?? 99;

            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best     = $sev;
            }
        }

        return $best;
    }
}