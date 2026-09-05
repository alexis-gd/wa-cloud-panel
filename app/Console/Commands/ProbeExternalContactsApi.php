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
            ['SYNC_API_URL',       $url ?: '(vacío)'],
            ['SYNC_API_AUTH',      config('contact_sync.auth')],
            ['SYNC_API_LOGIN_URL', config('contact_sync.login_url') ?: '(vacío)'],
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

        // El login se prueba por separado para poder decir CUÁL de los dos pasos falló:
        // credenciales malas y ruta mala se arreglan de formas muy distintas.
        if (config('contact_sync.auth') === 'login') {
            $this->newLine();
            $this->info('Haciendo login en ' . config('contact_sync.login_url') . ' ...');

            $sesion = $client->login();

            if (! $sesion['ok']) {
                $this->error('Login fallido: ' . $sesion['error']);
                return self::FAILURE;
            }

            // Del token solo el principio: es una credencial, no se imprime completa.
            $this->info('Login OK. Token recibido (' . substr($sesion['token'], 0, 12) . '...).');
            $this->line('  Este API entrega un JWT de vida corta, por eso se pide uno en cada corrida.');
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

            $this->mostrarEstados($filas);
        }

        $this->newLine();
        $this->info('Siguiente paso: php artisan contactos:sincronizar --dry-run');

        return self::SUCCESS;
    }

    /**
     * Valores distintos del campo de estado, con su conteo. Es lo que hace falta para
     * decidir con el cliente a quién sí darle de alta: su "BAJA" no es nuestro opt-out.
     */
    private function mostrarEstados(array $filas): void
    {
        $campos = array_map('trim', explode(',', (string) config('contact_sync.field_status')));
        $conteo = [];

        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $minus = array_change_key_case($fila, CASE_LOWER);

            foreach ($campos as $campo) {
                $valor = $minus[strtolower($campo)] ?? null;

                if ($valor !== null && $valor !== '') {
                    $conteo[trim((string) $valor)] = ($conteo[trim((string) $valor)] ?? 0) + 1;
                    break;
                }
            }
        }

        if ($conteo === []) {
            return;
        }

        ksort($conteo);

        $this->newLine();
        $this->line('Estados que trae el API (su clasificación de cartera, NO nuestro opt-out):');
        $this->table(['Estado', 'Registros'], collect($conteo)->map(fn ($n, $e) => [$e, $n])->values()->all());
        $this->line('El estado se guarda en la columna Cartera del contacto y se refresca en cada corrida.');
        $this->line('En Contactos se puede filtrar por él y de ahí etiquetar al grupo para una campaña.');
        $this->line('Para NO dar de alta alguno: SYNC_STATUS_EXCLUDE=BURÓ  (o SYNC_STATUS_INCLUDE=LIQUIDADO)');
    }

    private function recorte(mixed $valor, bool $completo): string
    {
        $json = json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $completo ? (string) $json : substr((string) $json, 0, 1200);
    }
}
