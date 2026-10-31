<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Olt } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';

const props = defineProps<{
    /** Open the dialog for this POP; null closes it. */
    popId: number | null;
    /** The OLT being edited; null adds a new one. */
    olt: Olt | null;
}>();

const emit = defineEmits<{
    saved: [];
    close: [];
}>();

const PORT_COUNTS = [1, 2, 4, 8, 16, 32, 64, 128];

const form = useForm({
    area_id: null as number | null,
    code: '',
    name: '',
    brand: '',
    ip_address: '',
    notes: '',
    cards: [] as { slot: number; ports: number }[],
});

watch(
    () => [props.popId, props.olt] as const,
    ([popId, olt]) => {
        if (popId === null) return;
        form.clearErrors();
        form.area_id = popId;
        form.code = olt?.code ?? '';
        form.name = olt && olt.name !== olt.code ? olt.name : '';
        form.brand = olt?.brand ?? '';
        form.ip_address = olt?.ip_address ?? '';
        form.notes = olt?.notes ?? '';
        // Cards as they exist now: highest port number per slot.
        const cards = new Map<number, number>();
        olt?.ports.forEach((p) => cards.set(p.slot, Math.max(cards.get(p.slot) ?? 0, p.port)));
        form.cards = cards.size ? [...cards.entries()].map(([slot, ports]) => ({ slot, ports })) : [{ slot: 1, ports: 8 }];
    },
    { immediate: true },
);

const totalPorts = computed(() => form.cards.reduce((sum, c) => sum + Number(c.ports || 0), 0));
const portChoices = (current: number) => [...new Set([...PORT_COUNTS, Number(current)])].sort((a, b) => a - b);

function addCard() {
    const next = Math.max(0, ...form.cards.map((c) => Number(c.slot))) + 1;
    form.cards.push({ slot: next, ports: form.cards.at(-1)?.ports ?? 8 });
}

const errors = computed(() => form.errors as Record<string, string>);
const cardError = computed(() => errors.value.cards ?? Object.entries(errors.value).find(([k]) => k.startsWith('cards.'))?.[1]);

function submit() {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') };
    if (props.olt) form.put(route('olts.update', { olt: props.olt.id }), options);
    else form.post(route('olts.store'), options);
}
</script>

<template>
    <Dialog :open="popId !== null" @update:open="(open) => !open && emit('close')">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ olt ? `Edit ${olt.code}` : 'Tambah OLT' }}</DialogTitle>
                <DialogDescription>Kartu PON menentukan port yang bisa disambungkan ke ODC.</DialogDescription>
            </DialogHeader>

            <form id="olt-form" class="grid gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="olt_code">Kode OLT</Label>
                        <Input id="olt_code" v-model="form.code" required placeholder="ZTE-C320 KJN-01" />
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="olt_brand">Merk / model</Label>
                        <Input id="olt_brand" v-model="form.brand" placeholder="ZTE C320" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="olt_name">Nama (opsional)</Label>
                        <Input id="olt_name" v-model="form.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="olt_ip">IP manajemen</Label>
                        <Input id="olt_ip" v-model="form.ip_address" inputmode="decimal" placeholder="10.0.0.2" />
                        <InputError :message="form.errors.ip_address" />
                    </div>
                </div>

                <div class="grid gap-2 rounded-md border p-3">
                    <div class="flex items-center justify-between">
                        <Label>Kartu PON ({{ totalPorts }} port)</Label>
                        <Button type="button" variant="outline" size="sm" @click="addCard"><Plus class="size-4" /> Kartu</Button>
                    </div>
                    <div v-for="(card, i) in form.cards" :key="i" class="flex items-center gap-2">
                        <span class="text-sm text-muted-foreground">Slot</span>
                        <Input v-model.number="card.slot" type="number" min="0" max="99" class="h-9 w-20" />
                        <NativeSelect v-model.number="card.ports" class="h-9 flex-1">
                            <option v-for="n in portChoices(card.ports)" :key="n" :value="n">{{ n }} port</option>
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-9 shrink-0"
                            title="Hapus kartu"
                            :disabled="form.cards.length === 1"
                            @click="form.cards.splice(i, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <p class="text-xs text-muted-foreground">Kartu atau port yang masih tersambung ke ODC tidak bisa dihapus.</p>
                    <InputError :message="cardError" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="olt_notes">Catatan</Label>
                    <textarea
                        id="olt_notes"
                        v-model="form.notes"
                        rows="2"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    />
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="emit('close')">Batal</Button>
                <Button type="submit" form="olt-form" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    Simpan
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
