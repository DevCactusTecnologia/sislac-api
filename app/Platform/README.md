# app/Platform — plano central

Tudo o que vive no banco `sislac_central`: `Tenant`, `User`, `Membership`,
`Plan`, `Subscription`, `ProvisioningRun`, `PlatformAudit`… (Fase 1).

Regras:

- Models declaram `protected $connection = 'central';`.
- Nada aqui referencia `App\Domain\*` nem `DB::connection('tenant')`.
- A única ponte com o laboratório é o middleware de tenancy
  (`App\Http\Middleware\EnsureTenantContext`, Fase 1).

O guard `scripts/check-no-central-in-tenant.sh` falha o CI se a fronteira for
cruzada em qualquer direção.
