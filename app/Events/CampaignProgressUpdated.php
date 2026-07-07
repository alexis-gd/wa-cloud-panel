<?php

namespace App\Events;

use App\Models\Campaign;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite mientras el worker envia una campana: sube contadores y estado.
 * El panel lo escucha por WebSocket para que la fila de la tabla y el modal
 * abierto suban solos (12/200 -> 13/200...), sin polling.
 *
 * Va por la cola (ShouldBroadcast, no ShouldBroadcastNow): no frena el envio ni
 * lo rompe si el servidor WS esta caido. Se dispara con throttle (ver el job):
 * maximo 1 por campana cada pocos segundos, mas el evento final garantizado.
 */
class CampaignProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $campaignId,
        public int $sentCount,
        public int $failedCount,
        public int $totalContacts,
        public string $status,
    ) {}

    public static function fromCampaign(Campaign $campaign): self
    {
        return new self(
            $campaign->id,
            (int) $campaign->sent_count,
            (int) $campaign->failed_count,
            (int) $campaign->total_contacts,
            $campaign->status,
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('campaigns');
    }

    // Nombre corto del evento para el frontend (Echo escucha '.campaign.progress').
    public function broadcastAs(): string
    {
        return 'campaign.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id'    => $this->campaignId,
            'sent_count'     => $this->sentCount,
            'failed_count'   => $this->failedCount,
            'total_contacts' => $this->totalContacts,
            'status'         => $this->status,
        ];
    }
}
