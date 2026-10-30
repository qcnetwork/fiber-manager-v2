<?php

namespace App\Services\Legacy;

use App\Models\Olt;
use App\Models\OltPort;
use Illuminate\Database\Connection;

/**
 * Turns the PON cards of the old "fiber-manager" app (table `pon`, one row per card with its ports in `pon_ports`)
 * into OLT ports here: the card number becomes the slot. Used by both migration commands.
 */
class OltCardImporter
{
    /** @var array<int, array{olt: int, slot: int}> old card (pon) id → new OLT id and slot */
    private array $cards = [];

    /** @var array<string, true> "oltId/slot/port" of every port that belongs to an imported card */
    private array $imported = [];

    /** @var list<string> */
    public array $notes = [];

    public int $cardCount = 0;

    public function __construct(private readonly Connection $old) {}

    /**
     * Creates the ports of every card of the given OLTs; existing ports are reused.
     *
     * @param  array<int|string, int>  $oltMap  old OLT id → new OLT id
     */
    public function importCards(array $oltMap): void
    {
        $oldPorts = $this->old->getSchemaBuilder()->hasTable('pon_ports')
            ? $this->old->table('pon_ports')->get()->groupBy('pon_id')
            : collect();

        foreach ($this->old->table('pon')->orderBy('id')->get() as $card) {
            $oltId = $oltMap[$card->olt_id] ?? null;
            if (! $oltId) {
                $this->notes[] = "Kartu PON \"{$card->name}\" dilewati: OLT-nya tidak dimigrasi.";

                continue;
            }

            $slot = (int) ($card->card_number ?? 1);
            $ports = $oldPorts->get($card->id, collect());
            $count = max(1, (int) ($card->port_count ?? 0), (int) $ports->max('port_number'));

            foreach (range(1, $count) as $number) {
                $this->port($oltId, $slot, $number);
            }
            foreach ($ports->where('status', 'maintenance') as $broken) {
                OltPort::where(['olt_id' => $oltId, 'slot' => $slot, 'port' => $broken->port_number])
                    ->update(['status' => 'rusak', 'notes' => $broken->description ?: null]);
            }

            $this->cards[$card->id] = ['olt' => $oltId, 'slot' => $slot];
            $this->cardCount++;
        }

        foreach (array_unique($oltMap) as $oltId) {
            Olt::withoutGlobalScopes()->whereKey($oltId)->update(['pon_ports' => OltPort::where('olt_id', $oltId)->count()]);
        }
    }

    /**
     * The new port for an old ODC's card and port number, created when the old data used a port past the card's size.
     */
    public function portId(?int $cardId, ?int $port): ?int
    {
        $card = $this->cards[$cardId] ?? null;
        if (! $card || ! $port) {
            return null;
        }

        return $this->port($card['olt'], $card['slot'], $port)->id;
    }

    /**
     * Whether a port came from an imported card (other ports of those OLTs are leftovers).
     */
    public function isImported(OltPort $port): bool
    {
        return isset($this->imported["{$port->olt_id}/{$port->slot}/{$port->port}"]);
    }

    private function port(int $oltId, int $slot, int $number): OltPort
    {
        $this->imported["{$oltId}/{$slot}/{$number}"] = true;

        return OltPort::firstOrCreate(['olt_id' => $oltId, 'slot' => $slot, 'port' => $number]);
    }
}
