<?php

namespace App\Http\Controllers\Atendimentos;

use App\Domain\Atendimentos\Actions\UpdateAtendimento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Atendimentos\UpdateAtendimentoRequest;
use App\Http\Resources\Atendimentos\AtendimentoResource;
use App\Platform\Authorization\MembershipAuthorizer;
use App\Platform\Authorization\TenantPermission;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class UpdateAtendimentoController extends Controller
{
    public function __invoke(
        UpdateAtendimentoRequest $request,
        UpdateAtendimento $updateAtendimento,
        MembershipAuthorizer $authorizer,
        int $id,
    ): JsonResponse {
        $payload = $request->validated();
        $user = $request->user();
        $userId = $user?->getAuthIdentifier();
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_string($userId) || ! is_string($tenantId)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $requiresEdit = $this->requiresEditPermission($payload);
        $requiresCancel = ($payload['cancelar'] ?? false) === true;
        $requiresPayment = array_key_exists('pagamentos', $payload);

        if (! $requiresEdit && ! $requiresCancel && ! $requiresPayment) {
            throw ValidationException::withMessages([
                'atendimento' => ['Nenhuma alteração suportada foi informada.'],
            ]);
        }

        if ($requiresEdit && ! $authorizer->allows($userId, $tenantId, TenantPermission::EditAppointment)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        if ($requiresCancel && ! $authorizer->allows($userId, $tenantId, TenantPermission::CancelAppointment)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        if ($requiresPayment && ! $authorizer->allows($userId, $tenantId, TenantPermission::RegisterPayment)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $justificativa = $payload['justificativa'] ?? null;
        unset($payload['justificativa']);

        $payload['_audit_user_id'] = $userId;
        $userEmail = $user->getAttribute('email');

        if (is_string($userEmail)) {
            $payload['_audit_user_email'] = $userEmail;
        }

        try {
            $atendimento = $updateAtendimento->handle(
                $id,
                $payload,
                is_string($justificativa) ? $justificativa : null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new AtendimentoResource($atendimento))->response();
    }

    /** @param array<string, mixed> $payload */
    private function requiresEditPermission(array $payload): bool
    {
        foreach ([
            'data',
            'paciente_id',
            'paciente_nome',
            'paciente_cpf',
            'paciente_nascimento',
            'solicitante',
            'convenio_id',
            'convenio_nome',
            'unidade_id',
            'origem_atendimento',
            'guia_numero',
            'guia_data',
            'jejum',
            'observacoes_assistente',
            'risco_cardiovascular',
            'prioridade_clinica',
            'exames',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }
}
