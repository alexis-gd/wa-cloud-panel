<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Services\OptOutWords;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reactiva contactos que quedaron de baja por una palabra que ya no da de baja.
 *
 * `NO`, `BAJA` y `CANCELAR` estuvieron en la lista de opt-out y produjeron bajas falsas:
 * el caso que lo destapó fue un contacto que ya había aceptado y contestó `No` a la
 * pregunta de un agente. Este comando los devuelve a `active`.
 *
 * Solo toca bajas con `opted_out_source = 'auto'` (las que disparó un texto) **y** cuyo
 * último mensaje entrante antes de la baja sea exactamente la palabra indicada. Las bajas
 * manuales, las de Meta (`whatsapp_131050`) y las que sí escribieron `STOP` quedan intactas.
 */
class UndoTextOptOutCommand extends Command
{
    protected $signature = 'contacts:undo-optout
                            {--word=NO : Palabra que causó la baja falsa (NO, BAJA, CANCELAR)}
                            {--phone= : Números concretos, separados por coma. Salta la búsqueda por palabra}
                            {--dry-run : Solo mostrar a quién afectaría, sin tocar nada}';

    protected $description = 'Reactiva contactos dados de baja por una palabra que ya no cuenta como baja';

    /** Margen para ligar la baja con el mensaje que la disparó (el opt-out es inmediato). */
    private const VENTANA_SEGUNDOS = 120;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Con --phone manda la auditoría humana: alguien ya revisó esas conversaciones una por
        // una y decidió. Buscar la palabra ahí sobraría y además falla en casos reales: un
        // contacto que escribió "No" y enseguida "Aún no" no queda ligado a una sola palabra.
        if ($this->option('phone')) {
            $afectados = $this->buscarPorTelefono();
            $origen    = 'los números indicados';
        } else {
            $palabra = OptOutWords::normalize((string) $this->option('word'));

            if (in_array($palabra, OptOutWords::WORDS, true)) {
                $this->error("'{$palabra}' sigue siendo una palabra de baja válida. No se revierte.");

                return self::FAILURE;
            }

            $afectados = $this->buscarPorPalabra($palabra);
            $origen    = "la palabra '{$palabra}'";
        }

        if ($afectados->isEmpty()) {
            $this->info("Sin contactos de baja por {$origen}. Nada que hacer.");

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nombre', 'Teléfono', 'Baja el'],
            $afectados->map(fn (Contact $c) => [
                $c->id,
                $c->name ?: '-',
                $c->phone,
                $c->opted_out_at?->setTimezone('America/Mexico_City')->format('Y-m-d H:i') ?? '-',
            ])->all(),
        );

        if ($dryRun) {
            $this->warn("DRY RUN: {$afectados->count()} contacto(s) se reactivarían. No se tocó nada.");

            return self::SUCCESS;
        }

        if (! $this->confirm("¿Reactivar {$afectados->count()} contacto(s)?", false)) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        foreach ($afectados as $contact) {
            $contact->update([
                'status'           => 'active',
                'opted_out_at'     => null,
                'opted_out_source' => null,
            ]);
        }

        $this->info("Listo: {$afectados->count()} contacto(s) reactivado(s).");
        $this->line('Nota: la asignación de agente que tenían no se recupera - la baja la borró.');

        return self::SUCCESS;
    }

    /**
     * Contactos nombrados a mano. Se exige que estén de baja, pero no se revisa qué palabra la
     * causó: eso ya lo verificó una persona.
     *
     * @return Collection<int, Contact>
     */
    private function buscarPorTelefono(): Collection
    {
        $numeros = collect(explode(',', (string) $this->option('phone')))
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->map(fn (string $n) => Contact::normalizePhone($n) ?? $n);

        $contactos = Contact::whereIn('phone', $numeros)->get();

        foreach ($numeros as $numero) {
            $contacto = $contactos->firstWhere('phone', $numero);

            if (! $contacto) {
                $this->warn("{$numero}: no existe en la base.");
            } elseif ($contacto->status !== 'opted_out') {
                $this->warn("{$numero}: no está de baja (está '{$contacto->status}'). Se omite.");
            }
        }

        return $contactos->where('status', 'opted_out')->values();
    }

    /**
     * Contactos de baja automática con esa palabra entre sus mensajes del momento de la baja.
     *
     * @return Collection<int, Contact>
     */
    private function buscarPorPalabra(string $palabra): Collection
    {
        return Contact::where('status', 'opted_out')
            ->where('opted_out_source', 'auto')
            ->whereNotNull('opted_out_at')
            ->get()
            ->filter(fn (Contact $c) => $this->laBajaLaDisparo($c, $palabra))
            ->values();
    }

    /**
     * Revisa TODOS los entrantes alrededor de la baja, no solo el último.
     *
     * Mirar solo el último no sirve: el caso que originó esto mandó "No" y enseguida "Aún no",
     * así que el último mensaje no era la palabra que disparó la baja. Y si entre esos mensajes
     * hay una palabra de baja que **sigue vigente**, la baja es legítima y no se toca.
     */
    private function laBajaLaDisparo(Contact $contact, string $palabra): bool
    {
        $desde = $contact->opted_out_at->copy()->subSeconds(self::VENTANA_SEGUNDOS);
        $hasta = $contact->opted_out_at->copy()->addSeconds(self::VENTANA_SEGUNDOS);

        $mensajes = Conversation::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->whereBetween('created_at', [$desde, $hasta])
            ->pluck('body');

        if ($mensajes->contains(fn (?string $m) => OptOutWords::matches((string) $m))) {
            return false;
        }

        return $mensajes->contains(fn (?string $m) => OptOutWords::normalize((string) $m) === $palabra);
    }
}
