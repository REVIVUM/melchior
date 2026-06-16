# Melchio — Motor de Regras Clínicas
----
## Estrutura

```text
melchio/
├── composer.json
├── docker-compose.yml
├── Dockerfile
├── .dockerignore
├── .gitignore
├── docker/
│   ├── nginx.conf
│   └── supervisord.conf
├── public/
│   └── index.php
├── rules/
│   └── clinical_rules.json
├── src/
│   └── Engine/
│       ├── ConditionEvaluator.php
│       ├── ContextNormalizer.php
│       └── RuleEngine.php
└── tests/
    └── test_payloads.json
```
---

## Como rodar

```bash
# 1. Entrar no diretório do projeto
cd melchio

# 2. Gerar autoload do Composer localmente (opcional, o Dockerfile faz isso)
composer install

# 3. Build e start
docker-compose up --build -d

# 4. Verificar saúde
curl http://localhost:8080/api/health
```

Exemplos de chamada

Avaliar paciente em emergência hipertensiva:

```bash
curl -s -X POST http://localhost:8080/api/evaluate \
  -H "Content-Type: application/json" \
  -d '{
    "patient": {
      "id": "pac-001",
      "name": "Maria Silva",
      "age": 65,
      "comorbidities": ["diabetes", "hipertensao", "obesidade"]
    },
    "observations": [
      {"type": "blood_pressure", "systolic_pa": 185, "diastolic_pa": 115},
      {"type": "glucose", "value": 280, "unit": "mg/dL"}
    ],
    "risks_noted": ["tosse_prolongada", "febre"]
  }' | python3 -m json.tool
```

Resposta esperada:

```json
{
    "patient_id": "pac-001",
    "evaluated_at": "2025-06-16T...",
    "triggered_rules": [
        {
            "name": "emergencia_hipertensiva",
            "priority": 1,
            "actions": [...]
        },
        {
            "name": "hipertensao_estagio2",
            "priority": 2,
            "actions": [...]
        },
        {
            "name": "diabetico_hipertenso_nao_controlado",
            "priority": 2,
            "actions": [...]
        },
        {
            "name": "suspeita_tuberculose",
            "priority": 3,
            "actions": [...]
        },
        {
            "name": "multimorbidade",
            "priority": 3,
            "actions": [...]
        }
    ],
    "alerts": [ ... ],
    "recommendations": [ ... ],
    "summary": {
        "total_rules_evaluated": 10,
        "total_rules_triggered": 5,
        "highest_severity": "critical"
    }
}
```

Listar regras carregadas:

```bash
curl http://localhost:8080/api/rules
```

Paciente saudável (sem alertas):

```bash
curl -s -X POST http://localhost:8080/api/evaluate \
  -H "Content-Type: application/json" \
  -d '{
    "patient": {"id": "pac-ok", "comorbidities": []},
    "observations": [
      {"type": "blood_pressure", "systolic_pa": 110, "diastolic_pa": 70},
      {"type": "glucose", "value": 95, "unit": "mg/dL"}
    ],
    "risks_noted": []
  }'
```

## Como adicionar novas regras

Basta editar rules/clinical_rules.json e reiniciar o container. O motor carrega as regras a cada requisição. Exemplo — adicionar regra de febre alta:

```json
{
  "name": "febre_alta",
  "priority": 2,
  "condition": { ">=": ["temperature", 39.0] },
  "actions": [
    {
      "type": "alert",
      "severity": "high",
      "message": "Febre alta (≥39°C). Possível quadro infeccioso."
    },
    {
      "type": "recommendation",
      "message": "Antipirético conforme prescrição. Investigar foco infeccioso."
    }
  ]
}
```

O normalizador já aceita observações do tipo temperature, heart_rate, spo2, weight e height — basta incluir no payload e criar as regras correspondentes.

---