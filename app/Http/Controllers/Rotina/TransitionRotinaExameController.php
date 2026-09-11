<?php

namespace App\Http\Controllers\Rotina;

use App\Domain\Atendimentos\Actions\TransitionAtendimentoExame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rotina\TransitionRotinaExameRequest;
use App\Platform\Supabase\SupabaseAuthUser;
use App\Platform\Supabase\SupabasePermissionAuthorizer;
use DomainException;
use Illuminate\Http\JsonResponse;
use LogicException;

final class TransitionRotinaExameController extends Controller
{
    public function __invoke(
        TransitionRotinaExameRequest $request,
        TransitionAtendimentoExame $transition,
        SupabasePermissionAuthorizer $authorizer,
        int $id,
    ): JsonResponse {
        $payload = $request->validated();
        $action = $payload['acao'] ?? null;
        $principal = $request->user();

        if (! is_string($action) || ! $principal instanceof SupabaseAuthUser) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $permission = match ($action) {
            'coletar', 'recoletar' => 'registrar_coleta',
            'iniciar_analise', 'finalizar_analise' => 'analisar_amostra',
            'cancelar' => 'cancelar_atendimento',
            default => throw new LogicException('Ação de rotina não reconhecida.'),
        };

        $userId = $principal->getKey();

        if (! $authorizer->allows($userId, $permission)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $email = $principal->getAttribute('email');
        $actorEmail = is_string($email) ? $email : '';
        $actorName = $actorEmail !== '' ? $actorEmail : $userId;
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
