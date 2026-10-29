<?php

namespace App\Console\Commands;

use App\Models\PhoneNumber;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Console\Command;

/**
 * Somete a Meta la plantilla de Autenticacion para los OTP.
 *
 * **El texto de una plantilla de Autenticacion NO se escribe.** Meta lo pone fijo segun el
 * idioma; nosotros solo elegimos tres cosas: si lleva la advertencia de seguridad, en cuantos
 * minutos dice que caduca, y que boton trae. Por eso aqui no hay copy que revisar - y por eso
 * el riesgo de rechazo por redaccion es practicamente cero.
 *
 * **Por que COPY_CODE y no autocompletado:** el autocompletado (`ONE_TAP`) necesita el
 * `package_name` y el `signature_hash` de la app Android del cliente, que no tenemos. Con
 * COPY_CODE el usuario toca el boton, se copia el codigo y lo pega en la app. Funciona en
 * cualquier telefono, incluido iPhone. Si algun dia nos dan los datos de la app, la plantilla
 * se puede actualizar sin empezar de cero: el autocompletado siempre se cae a COPY_CODE cuando
 * la verificacion falla, asi que el boton de copiar nunca se va.
 *
 * El comando NO manda nada sin `--enviar`. Someter una plantilla no afecta el score de la
 * cuenta (solo los rechazos repetidos del mismo contenido), pero sigue siendo una accion sobre
 * la cuenta de Meta del cliente, asi que por default solo imprime lo que se enviaria.
 */
class CreateOtpTemplate extends Command
{
    protected $signature = 'wa:crear-plantilla-otp
                            {--nombre=prestamaz_otp_v1 : Nombre de la plantilla en Meta}
                            {--minutos=10 : Minutos que el pie de la plantilla dira que dura el codigo}
                            {--enviar : Someterla de verdad. Sin esto solo imprime el payload}';

    protected $description = 'Somete a Meta la plantilla de Autenticacion para OTP (dry-run por default)';

    /** Meta acepta de 1 a 90 minutos en el pie de caducidad. */
    private const MIN_MINUTOS = 1;
    private const MAX_MINUTOS = 90;

    public function handle(WhatsAppClient $client): int
    {
        $minutos = (int) $this->option('minutos');

        if ($minutos < self::MIN_MINUTOS || $minutos > self::MAX_MINUTOS) {
            $this->error("Los minutos deben ir entre " . self::MIN_MINUTOS . " y " . self::MAX_MINUTOS . ".");

            return self::FAILURE;
        }

        $wabaId = config('services.whatsapp.waba_id');

        if (! $wabaId) {
            $this->error('Falta WA_WABA_ID en el .env.');

            return self::FAILURE;
        }

        $payload = $this->payload($this->option('nombre'), $minutos);

        $this->mostrar($payload, $wabaId, $minutos);

        if (! $this->option('enviar')) {
            $this->warn('  DRY-RUN: no se envio nada. Agrega --enviar para someterla.');
            $this->line('');

            return self::SUCCESS;
        }

        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone || ! $phone->token) {
            $this->error('No hay un numero activo con token en la base.');

            return self::FAILURE;
        }

        $this->line('  Sometiendo a Meta...');

        $res = $client->postPath("{$wabaId}/message_templates", $phone->token, $payload);

        if (! $res['ok']) {
            $this->error('  Meta rechazo la peticion: '
                . data_get($res['body'], 'error.message', 'sin detalle'));
            $this->line('  Revisa storage/logs para la respuesta completa.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('  Sometida. Meta tarda hasta 24h en revisarla.');
        $this->line('  id: ' . data_get($res['body'], 'id', '?'));
        $this->line('  estado: ' . data_get($res['body'], 'status', 'PENDING'));
        $this->line('');
        $this->line('  Cuando la aprueben, sincronizala al panel con: php artisan wa:sync-templates');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * El cuerpo de la peticion.
     *
     * El BODY no lleva `text`: Meta no lo acepta en esta categoria porque el texto es suyo.
     * `add_security_recommendation` agrega la advertencia de no compartir el codigo - va en true
     * porque es una financiera y el fraude por codigo compartido es el riesgo real del canal.
     */
    private function payload(string $nombre, int $minutos): array
    {
        return [
            'name'     => $nombre,
            'category' => 'AUTHENTICATION',
            'language' => 'es_MX',
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => $minutos],
                [
                    'type'    => 'BUTTONS',
                    'buttons' => [[
                        'type'     => 'OTP',
                        'otp_type' => 'COPY_CODE',
                        // Etiqueta del boton. Tope de 15 caracteres en Meta.
                        'text'     => 'Copiar código',
                    ]],
                ],
            ],
        ];
    }

    private function mostrar(array $payload, string $wabaId, int $minutos): void
    {
        $this->line('');
        $this->line('  POST ' . config('services.whatsapp.api_version')
            . "/{$wabaId}/message_templates");
        $this->line('');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('');
        $this->line('  Como le va a llegar al usuario (el texto lo pone Meta, no nosotros):');
        $this->line('');
        $this->line('    <codigo> es tu código de verificación.');
        $this->line('    Por tu seguridad, no lo compartas.');
        $this->line("    Este código caduca en {$minutos} minutos.");
        $this->line('    [ Copiar código ]');
        $this->line('');
        $this->warn("  OJO: esos {$minutos} minutos son SOLO TEXTO. Quien tiene que hacer caducar");
        $this->warn('  el codigo de verdad es nuestro API (M5). Si no coinciden, el usuario lee');
        $this->warn('  una cosa y le pasa otra.');
        $this->line('');
    }
}
