<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estudianteseventos extends Model
{
    protected $table = 'estudianteseventos';

    protected $fillable = [
        'instituciones_id',
        'eventos_id',
        'estudiantesifas_id',
        'Ap_Paterno',
        'Ap_Materno',
        'Nombres',
        'Carnet',
        'Celular',
        'Correo',
        'DatosEspeciales',
        'TienePago',
        'Monto',
        'MetodoPago',
        'FechaPago',
        'ComprobantePago',
        'EstadoPago',
        'EstadoInscripcion',
        'Especialidad',
        'Categoria',
        'CertificadoPdf',
        'CertificadoGeneradoAt',
        'Observacion',
        'Foto',
        'FechaNac',
        'Edad',
        'Tutor',
        'CelularTutor',
        'Departamento',
        'NombreInstitucion',
        'CertificadoNacimiento',

    ];
}
