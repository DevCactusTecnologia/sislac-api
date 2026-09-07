<?php

use App\Platform\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('admin:super-user {email : E-mail do Super Admin}', function (string $email): int {
    $email = trim($email);

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $this->error('E-mail inválido.');

        return Command::FAILURE;
    }

    $existing = User::query()->where('email', $email)->first();

    if ($existing !== null) {
        $existing->setAttribute('is_super_admin', true);
        $existing->save();

        $this->info('Super Admin atualizado.');

        return Command::SUCCESS;
    }

    $name = trim((string) $this->ask('Nome'));
    $password = (string) $this->secret('Senha');
    $confirmation = (string) $this->secret('Confirme a senha');

    if ($name === '' || $password === '') {
        $this->error('Nome e senha são obrigatórios.');

        return Command::FAILURE;
    }

    if (! hash_equals($password, $confirmation)) {
        $this->error('A confirmação da senha não confere.');

        return Command::FAILURE;
    }

    DB::connection('central')->transaction(function () use ($name, $email, $password): void {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->setAttribute('is_super_admin', true);
        $user->save();
    });

    $this->info('Super Admin criado.');

    return Command::SUCCESS;
})->purpose('Cria ou promove um usuário central a Super Admin');
