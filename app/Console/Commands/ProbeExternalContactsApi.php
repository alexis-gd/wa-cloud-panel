<?php

namespace App\Console\Commands;

use App\Services\Contacts\ExternalContactsClient;
use Illuminate\Console\Command;

/**
 * Diagnóstico del API de contactos del cliente: ¿llega, autentica, y qué devuelve?
 *
 * Existe para poder verificar la conexión en el VPS después de un deploy sin escribir nada
 * en la base. Muestra la respuesta cruda y qué campos detecta, que es justo lo que hace falta
 * para dejar bien `SYNC_FIELD_PHONE` y `SYNC_FIELD_NAME`.
 */
class ProbeExternalContactsApi extends Command
{
    protected $signature = 'contactos:probar-api {--raw : Muestra la respuesta completa sin recortar}';

    protected $description = 'Prueba la conexión con el API de contactos del cliente y muestra qué devuelve';

    public function handle(ExternalContactsClient $client): int
    {
        $url = config('contact_sync.url');

        $this->info('Configuración actual:');
        $this->table(['Variable', 'Valor'], [
            ['SYNC_API_URL',     $url ?: '(vacío)'],
            ['SYNC_API_AUTH',    config('contact_sync.auth')],
            ['SYNC_API_USER',    config('contact_sync.user') ?: '(vacío)'],
            // La contraseña nunca se imprime: solo si está puesta o no.
            ['SYNC_API_PASSWORD', config('contact_sync.password') ? '(configurada)' : '(vacía)'],
            ['SYNC_API_ROOT',    config('contact_sync.root') ?: '(la respuesta es la lista)'],
            ['SYNC_FIELD_PHONE', config('contact_sync.field_phone')],
            ['SYNC_FIELD_NAME',  config('contact_sync.field_name')],
            ['SYNC_TAG',         config('contact_sync.tag') ?: '(sin etiqueta)'],
        ]);

        if (empty($url)) {
            $this->error('Falta SYNC_API_URL en el .env. Sin eso no hay a dónde consultar.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Consultando {$url} ...");

        $r = $client->fetch();

        if (! $r['ok']) {
            $this->error('Falló: ' . $r['error']);

            // El error de red y el de credenciales se arreglan de formas muy distintas.
            match (true) {
                $r['status'] === 0   => $this->line('  No hubo respuesta: revisa el puerto, la ruta o el firewall del servidor del cliente.'),
                $r['status'] === 401 => $this->line('  Llegó, pero rechazó las credenciales: revisa SYNC_API_USER y SYNC_API_PASSWORD.'),
                $r['status'] === 404 => $this->line('  El servidor responde, pero esa ruta no existe: revisa SYNC_API_URL.'),
                default              => $this->line('  El servidor respondió con error. Revisa el cuerpo de abajo.'),
            };

            if ($r['body']) {
                $this->newLine();
                $this->line(substr(is_string($r['body']) ? $r['body'] : json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 800));
            }

            return self::FAILURE;
        }

        $this->info("Respondió HTTP {$r['status']}.");

        $root  = (string) config('contact_sync.root', '');
        $filas = $root === '' ? $r['body'] : data_get($r['body'], $root);

        if (! is_array($filas) || ! array_is_list($filas)) {
            $this->newLine();
            $this->warn('La respuesta llegó, pero no encuentro una LISTA de contactos.');
            $this->line('Ajusta SYNC_API_ROOT para apuntar al arreglo. Estructura recibida:');
            $this->newLine();
            $this->line($this->recorte($r['body'], $this->option('raw')));

            if (is_array($r['body'])) {
                $this->newLine();
                $this->line('Llaves del primer nivel: ' . implode(', ', array_keys($r['body'])));
            }

            return self::FAILURE;
        }

        $this->info('Encontré ' . count($filas) . ' registro(s).');

        if ($filas === []) {
            $this->warn('La lista viene vacía: no hay nada que dar de alta ahora mismo.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Primer registro tal como llega:');
        $this->line($this->recorte($filas[0], $this->option('raw')));

        if (is_array($filas[0])) {
            $this->newLine();
            $this->line('Campos disponibles: ' . implode(', ', array_keys($filas[0])));
            $this->line('Ajusta SYNC_FIELD_PHONE y SYNC_FIELD_NAME con los nombres de arriba.');
        }

        $this->newLine();
        $this->info('Siguiente paso: php artisan contactos:sincronizar --dry-run');

        return self::SUCCESS;
    }

    private function recorte(mixed $valor, bool $completo): string
    {
        $json = json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $completo ? (string) $json : substr((string) $json, 0, 1200);
    }
}
