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
                            {--phone= : Revertir un solo contacto por su número}
                            {--dry-run : Solo mostrar a quién afectaría, sin tocar nada}';

    protected $description = 'Reactiva contactos dados de baja por una palabra que ya no cuenta como baja';

    /** Margen para ligar la baja con el mensaje que la disparó (el opt-out es inmediato). */
    private const VENTANA_SEGUNDOS = 120;

    public function handle(): int
    {
        $palabra = OptOutWords::normalize((string) $this->option('word'));
        $dryRun  = (bool) $this->option('dry-run');

        if (in_array($palabra, OptOutWords::WORDS, true)) {
            $this->error("'{$palabra}' sigue siendo una palabra de baja válida. No se revierte.");

            return self::FAILURE;
        }

        $afectados = $this->buscarAfectados($palabra);

        if ($afectados->isEmpty()) {
            $this->info("Sin contactos dados de baja por '{$palabra}'. Nada que hacer.");

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
     * Contactos de baja automática cuyo último entrante justo antes de la baja fue esa palabra.
     *
     * @return Collection<int, Contact>
     */
    private function buscarAfectados(string $palabra): Collection
    {
        $query = Contact::where('status', 'opted_out')
            ->where('opted_out_source', 'auto')
            ->whereNotNull('opted_out_at');

        if ($phone = $this->option('phone')) {
            $query->where('phone', Contact::normalizePhone($phone) ?? $phone);
        }

        return $query->get()->filter(
            fn (Contact $c) => $this->laBajaLaDisparo($c, $palabra),
        )->values();
    }

    private function laBajaLaDisparo(Contact $contact, string $palabra): bool
    {
        $mensaje = Conversation::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->where('created_at', '<=', $contact->opted_out_at->copy()->addSeconds(self::VENTANA_SEGUNDOS))
            ->latest('created_at')
            ->value('body');

        return $mensaje !== null && OptOutWords::normalize($mensaje) === $palabra;
    }
}
