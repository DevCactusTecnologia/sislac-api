<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Actions\TransitionAtendimentoExame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rotina\TransitionRotinaExameRequest;
use App\Platform\Authorization\MembershipAuthorizer;
use App\Platform\Authorization\TenantPermission;
use App\Platform\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;

final class TransitionRotinaExameController extends Controller
{
    public function __invoke(
        TransitionRotinaExameRequest $request,
        TransitionAtendimentoExame $transition,
        MembershipAuthorizer $authorizer,
        int $id,
    ): JsonResponse {
        $payload = $request->validated();
        $action = $payload['acao'] ?? null;
        $user = $request->user();
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_string($action) || ! $user instanceof User || ! is_string($tenantId)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $permission = match ($action) {
            'coletar', 'recoletar' => TenantPermission::RegisterCollection,
            'iniciar_analise', 'finalizar_analise' => TenantPermission::AnalyzeSample,
            'cancelar' => TenantPermission::CancelAppointment,
        };

        $userId = (string) $user->getKey();

        if (! $authorizer->allows($userId, $tenantId, $permission)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $name = $user->getAttribute('name');
        $email = $user->getAttribute('email');
        $actorEmail = is_string($email) ? $email : '';
        $actorName = is_string($name) && trim($name) !== '' ? trim($name) : $actorEmail;
        $reason = $payload['motivo'] ?? null;

        try {
            $exam = $transition->handle(
                $id,
                $action,
                $userId,
                $actorName,
                $actorEmail,
                is_string($reason) ? $reason : null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $exam->toArray()]);
    }
}
