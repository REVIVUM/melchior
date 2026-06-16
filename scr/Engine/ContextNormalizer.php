<?php

declare(strict_types=1);

namespace Melchio\Engine;

/**
 * Normaliza o payload de entrada em um contexto plano para avaliação.
 *
 * Converte observações no array em chaves diretas:
 *   blood_pressure → bp_systolic, bp_diastolic
 *   glucose        → glucose
 *   temperature    → temperature
 *   heart_rate     → heart_rate
 *   spo2           → spo2
 *   weight         → weight
 *   height         → height
 *
 * Mantém patient (objeto) e risks_noted (array) como estão.
 * Achata patient.age em patient_age para fácil acesso nas regras.
 */
class ContextNormalizer
{
    /**
     * @var array<string, callable> Mapa de tipo de observação → normalizador
     */
    private array $normalizers = [
        'blood_pressure' => 'normalizeBloodPressure',
        'glucose'        => 'normalizeGlucose',
        'temperature'    => 'normalizeTemperature',
        'heart_rate'     => 'normalizeHeartRate',
        'spo2'           => 'normalizeSpO2',
        'weight'         => 'normalizeWeight',
        'height'         => 'normalizeHeight',
    ];

    public function normalize(array $input): array
    {
        $context = [];

        // ── Paciente ──────────────────────────────────────────────────────────
        if (isset($input['patient'])) {
            $context['patient'] = $input['patient'];

            // Achatar campos úteis para acesso direto nas regras
            if (isset($input['patient']['age'])) {
                $context['patient_age'] = (int) $input['patient']['age'];
            }
            if (isset($input['patient']['sex'])) {
                $context['patient_sex'] = strtolower((string) $input['patient']['sex']);
            }
        }

        // ── Riscos notados ────────────────────────────────────────────────────
        if (isset($input['risks_noted']) && is_array($input['risks_noted'])) {
            $context['risks_noted'] = $input['risks_noted'];
        }

        // ── Observações (normalizar para contexto plano) ──────────────────────
        if (isset($input['observations']) && is_array($input['observations'])) {
            foreach ($input['observations'] as $obs) {
                $type = $obs['type'] ?? null;

                if ($type && isset($this->normalizers[$type])) {
                    $method = $this->normalizers[$type];
                    $this->$method($obs, $context);
                }
            }
        }

        return $context;
    }

    // ── Normalizadores específicos ────────────────────────────────────────────

    private function normalizeBloodPressure(array $obs, array &$context): void
    {
        if (isset($obs['systolic_pa'])) {
            $context['bp_systolic'] = (float) $obs['systolic_pa'];
        }
        if (isset($obs['diastolic_pa'])) {
            $context['bp_diastolic'] = (float) $obs['diastolic_pa'];
        }
    }

    private function normalizeGlucose(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['glucose'] = (float) $obs['value'];
        }
    }

    private function normalizeTemperature(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['temperature'] = (float) $obs['value'];
        }
    }

    private function normalizeHeartRate(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['heart_rate'] = (int) $obs['value'];
        }
    }

    private function normalizeSpO2(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['spo2'] = (float) $obs['value'];
        }
    }

    private function normalizeWeight(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['weight'] = (float) $obs['value'];
        }
    }

    private function normalizeHeight(array $obs, array &$context): void
    {
        if (isset($obs['value'])) {
            $context['height'] = (float) $obs['value'];
        }
    }
}