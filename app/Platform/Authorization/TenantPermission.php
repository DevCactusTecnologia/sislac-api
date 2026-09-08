<?php

namespace App\Platform\Authorization;

enum TenantPermission: string
{
    case ViewPatients = 'visualizar_pacientes';
    case CreatePatient = 'cadastrar_paciente';
    case EditPatient = 'editar_paciente';
    case ViewAppointments = 'visualizar_atendimentos';
}
