<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validacion de los dos endpoints de OTP.
 *
 * Hereda la leccion de `ContactedRequest`: un API con dos formatos de error es un bug aunque
 * cada uno funcione por separado. Quien lo consume es el programador de la app del cliente, y
 * se le documento un solo contrato - `{status, message, code}`, en espanol.
 */
class OtpRequest extends FormRequest
{
    /** La autorizacion la resuelve `ApiKeyMiddleware`, antes de llegar aqui. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El `code` solo se exige al verificar. La ruta de envio no lo manda.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $reglas = [
            'phone'     => 'required|string|max:20',
            'reference' => 'nullable|string|max:100',
        ];

        if ($this->routeIs('otp.verify')) {
            $reglas['code'] = 'required|string|digits:6';
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Falta el numero (phone). Mandalo a 10 digitos o con el 52 adelante.',
            'phone.max'      => 'El numero (phone) es demasiado largo.',
            'code.required'  => 'Falta el codigo (code).',
            'code.digits'    => 'El codigo (code) son 6 digitos.',
            'reference.max'  => 'La referencia (reference) no puede pasar de 100 caracteres.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => $validator->errors()->first(),
            'code'    => 'INVALID_PARAMS',
        ], 422));
    }
}
