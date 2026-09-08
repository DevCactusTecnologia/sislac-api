<?php

namespace App\Platform\Authorization;

enum TenantPermission: string
{
    case ViewPatients = 'visualizar_pacientes';
    case CreatePatient = 'cadastrar_paciente';
    case EditPatient = 'editar_paciente';
    case ViewAppointments = 'visualizar_atendimentos';
    case CreateAppointment = 'criar_atendimento';
    case EditAppointment = 'editar_atendimento';
    case CancelAppointment = 'cancelar_atendimento';
    case RegisterPayment = 'registrar_pagamento';
}
