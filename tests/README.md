# Testes Iniciais (PR-04)

Este diretório contém smoke tests básicos de segurança/sessão.

## Executar

```bash
chmod +x tests/smoke_basico.sh
./tests/smoke_basico.sh "http://localhost/sigmon/public"
```

O primeiro argumento é a URL base da aplicação (padrão:
`http://localhost/sigmon/public`).

## Cobertura inicial

- `api_health` sem autenticação deve retornar `401`.
- Endpoint protegido sem sessão deve retornar `401`.
- Método HTTP inválido deve retornar `405`.
