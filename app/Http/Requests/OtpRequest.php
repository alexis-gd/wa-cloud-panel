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
     * El `code` es obligatorio al verificar y **opcional** al enviar.
     *
     * Al enviar, si viene, es el codigo que la app quiere que reciba el usuario; si no viene,
     * lo generamos nosotros. Asi quedo cotizado el servicio ("recibe el numero y el codigo") y
     * asi sirve para quien no quiera generarlo.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'phone'     => 'required|string|max:20',
            'reference' => 'nullable|string|max:100',
            'code'      => ($this->routeIs('otp.verify') ? 'required' : 'nullable') . '|string|digits:6',
        ];
    }

    /**
     * Rechaza cualquier campo que no sea de los de arriba.
     *
     * Laravel ignora en silencio lo que no valida, y en un API eso es una trampa: una peticion
     * con `codigo` en vez de `code`, o con un campo que el programador cree que configura algo,
     * responde 201 como si lo hubiera entendido. Quien la escribio se queda convencido de que
     * mando una cosa mientras al usuario le llega otra. Mejor fallar y decir cual sobra.
     */
    protected function passedValidation(): void
    {
        $sobran = array_diff(array_keys($this->all()), array_keys($this->rules()));

        if ($sobran) {
            throw new HttpResponseException(response()->json([
                'status'  => 'error',
                'message' => 'No reconozco estos campos: ' . implode(', ', $sobran)
                             . '. Los que acepto aqui son: ' . implode(', ', array_keys($this->rules())) . '.',
                'code'    => 'UNKNOWN_PARAMS',
            ], 422));
        }
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
