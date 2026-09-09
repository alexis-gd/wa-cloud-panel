<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validación de `GET /api/contacted`.
 *
 * Existe por una razón concreta: la validación por default de Laravel responde con SU formato
 * (`{message, errors:{...}}`) y en inglés. Este API lo consume un sistema externo del cliente,
 * al que se le documentó un contrato distinto - `{status, message, code}`, en español. Sin
 * esto, el API contestaba de dos formas según el error, y su programador se topaba con la
 * inglesa al primer intento con una fecha mal escrita.
 *
 * Lo detectó el cliente probando la colección de Postman, no nosotros.
 */
class ContactedRequest extends FormRequest
{
    /** La autorización la resuelve `ApiKeyMiddleware`, antes de llegar aquí. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'date'     => 'nullable|date_format:Y-m-d',
            'from'     => 'nullable|date_format:Y-m-d|required_with:to',
            'to'       => 'nullable|date_format:Y-m-d|required_with:from',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * Mensajes en español y accionables: quien los lee es un programador que no tiene la
     * documentación enfrente, así que cada uno dice qué mandar, no solo qué está mal.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'La fecha (date) debe ir como AAAA-MM-DD. Ejemplo: 2026-08-24.',
            'from.date_format' => 'La fecha inicial (from) debe ir como AAAA-MM-DD. Ejemplo: 2026-08-01.',
            'to.date_format'   => 'La fecha final (to) debe ir como AAAA-MM-DD. Ejemplo: 2026-08-31.',
            'from.required_with' => 'Si mandas "to" también tienes que mandar "from".',
            'to.required_with'   => 'Si mandas "from" también tienes que mandar "to".',
            'page.integer'     => 'La página (page) debe ser un número entero.',
            'page.min'         => 'La página (page) empieza en 1.',
            'per_page.integer' => 'El tamaño de página (per_page) debe ser un número entero.',
            'per_page.min'     => 'El tamaño de página (per_page) debe ser al menos 1.',
        ];
    }

    /**
     * Devuelve el mismo formato que el resto del API en lugar del de Laravel.
     *
     * Se manda UN solo mensaje (el primero) y no el arreglo de todos: el contrato que se le
     * documentó al cliente tiene un `message` de texto, y devolverle a veces texto y a veces
     * una lista lo obligaría a manejar dos formas.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => $validator->errors()->first(),
            'code'    => 'INVALID_PARAMS',
        ], 422));
    }
}
