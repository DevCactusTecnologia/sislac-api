<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Actions\TransitionAtendimentoExame;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSupabaseUser;
use App\Http\Requests\Rotina\TransitionRotinaExameRequest;
use App\Platform\Authorization\MembershipAuthorizer;
use App\Platform\Authorization\TenantPermission;
use App\Platform\Models\User;
use App\Platform\Supabase\SupabasePrincipal;
use DomainException;
use Illuminate\Http\JsonResponse;
use LogicException;

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
        $principal = $request->attributes->get(AuthenticateSupabaseUser::REQUEST_ATTRIBUTE);
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_string($action) || ! $principal instanceof SupabasePrincipal || ! is_string($tenantId)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $permission = match ($action) {
            'coletar', 'recoletar' => TenantPermission::RegisterCollection,
            'iniciar_analise', 'finalizar_analise' => TenantPermission::AnalyzeSample,
            'cancelar' => TenantPermission::CancelAppointment,
            default => throw new LogicException('Ação de rotina não reconhecida.'),
        };

        $userId = $principal->getKey();

        if (! $authorizer->allows($userId, $tenantId, $permission)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        // Compatibilidade transitória enquanto memberships/central ainda existem nesta etapa.
        // A Task 3 remove este lookup junto com a autorização central.
        $centralUser = User::query()->find($userId);
        $email = $principal->getAttribute('email');
        $actorEmail = is_string($email) ? $email : '';
        $centralName = $centralUser?->getAttribute('name');
        $actorName = is_string($centralName) && trim($centralName) !== ''
            ? trim($centralName)
            : $actorEmail;
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
