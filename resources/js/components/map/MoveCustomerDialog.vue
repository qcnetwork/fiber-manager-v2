<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { getJson } from '@/lib/http';
import type { Customer, NearestOdp, PortStatus } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    customer: Customer | null;
}>();

const emit = defineEmits<{
    moved: [];
    close: [];
}>();

interface OdpChoice {
    id: number;
    code: string;
    detail: string;
}

/** Where to look for nearby ODPs: the customer's house, else their current ODP. */
const origin = computed(() => {
    const c = props.customer;
    if (c?.lat != null && c.lng != null) return { lat: c.lat, lng: c.lng, label: 'rumah pelanggan' };
    const odp = c?.port?.odp;
    return odp ? { lat: odp.lat, lng: odp.lng, label: `ODP ${odp.code}` } : null;
});

const query = ref('');
const nearby = ref<OdpChoice[]>([]);
const found = ref<OdpChoice[]>([]);
const loadingList = ref(false);

const chosenOdp = ref<OdpChoice | null>(null);
const ports = ref<{ id: number; number: number; status: PortStatus; notes: string | null }[]>([]);
const loadingPorts = ref(false);

const form = useForm({ port_id: null as number | null });

watch(
    () => props.customer,
    async (customer) => {
        if (!customer) return;
        query.value = '';
        found.value = [];
        chosenOdp.value = null;
        form.reset();
        form.clearErrors();
        nearby.value = [];
        if (!origin.value) return;

        loadingList.value = true;
        try {
            const data = await getJson<{ odps: NearestOdp[] }>(route('map.nearest'), { lat: origin.value.lat, lng: origin.value.lng, radius: 1000 });
            nearby.value = data.odps.map((o) => ({ id: o.id, code: o.code, detail: `${o.distance} m · ${o.free_ports} port kosong` }));
        } finally {
            loadingList.value = false;
        }
    },
    { immediate: true },
);

const runSearch = useDebounceFn(async () => {
    const q = query.value.trim();
    if (q.length < 2) {
        found.value = [];
        return;
    }
    const results = await getJson<{ kind: string; id: number; code: string; name: string | null }[]>(route('map.search'), { q });
    found.value = results.filter((r) => r.kind === 'odp').map((r) => ({ id: r.id, code: r.code, detail: r.name ?? '' }));
}, 300);
watch(query, runSearch);

const choices = computed(() => (query.value.trim().length >= 2 ? found.value : nearby.value));

async function chooseOdp(odp: OdpChoice) {
    chosenOdp.value = odp;
    form.port_id = null;
    form.clearErrors();
    loadingPorts.value = true;
    try {
        ports.value = (await getJson<{ ports: typeof ports.value }>(route('odps.free-ports', { odp: odp.id }))).ports;
    } finally {
        loadingPorts.value = false;
    }
}

const isCurrentOdp = (id: number) => props.customer?.port?.odp?.id === id;

function submit() {
    form.post(route('customers.move', { customer: props.customer!.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('moved'),
    });
}
</script>

<template>
    <Dialog :open="!!customer" @update:open="(open) => !open && emit('close')">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Pindah {{ customer?.name }}</DialogTitle>
                <DialogDescription>
                    Sekarang:
                    <b>{{ customer?.port?.odp ? `${customer.port.odp.code} port ${customer.port.number}` : 'belum terhubung ke port' }}</b
                    >. Port lama otomatis menjadi kosong.
                </DialogDescription>
            </DialogHeader>

            <!-- Step 1: choose the ODP -->
            <div v-if="!chosenOdp" class="grid gap-3">
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="query" class="pl-8" placeholder="Cari kode ODP…" />
                </div>
                <p v-if="query.trim().length < 2" class="text-xs text-muted-foreground">
                    {{ origin ? `ODP dengan port kosong dalam 1 km dari ${origin.label}:` : 'Ketik kode ODP tujuan.' }}
                </p>
                <div v-if="loadingList" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <LoaderCircle class="size-4 animate-spin" /> Memuat…
                </div>
                <ul v-else-if="choices.length" class="divide-y rounded-md border text-sm">
                    <li v-for="odp in choices" :key="odp.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-muted"
                            @click="chooseOdp(odp)"
                        >
                            <span class="truncate font-medium"
                                >{{ odp.code }}
                                <span v-if="isCurrentOdp(odp.id)" class="text-xs font-normal text-muted-foreground">(sekarang)</span></span
                            >
                            <span class="shrink-0 text-xs text-muted-foreground">{{ odp.detail }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">Tidak ada ODP yang cocok.</p>
            </div>

            <!-- Step 2: choose the port -->
            <form v-else id="move-form" class="grid gap-3" @submit.prevent="submit">
                <div class="flex items-center gap-2">
                    <Button type="button" variant="ghost" size="icon" title="Pilih ODP lain" @click="chosenOdp = null"
                        ><ArrowLeft class="size-4"
                    /></Button>
                    <div class="font-semibold">{{ chosenOdp.code }}</div>
                </div>
                <div v-if="loadingPorts" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <LoaderCircle class="size-4 animate-spin" /> Memuat port…
                </div>
                <p v-else-if="!ports.length" class="text-sm text-muted-foreground">ODP ini tidak punya port kosong.</p>
                <div v-else class="grid grid-cols-4 gap-2">
                    <button
                        v-for="port in ports"
                        :key="port.id"
                        type="button"
                        class="flex h-12 flex-col items-center justify-center rounded-md border-2 text-sm font-semibold"
                        :class="
                            form.port_id === port.id
                                ? 'border-blue-600 bg-blue-600 text-white'
                                : port.status === 'booking'
                                  ? 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100'
                                  : 'border-green-500 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200'
                        "
                        :title="port.notes ?? port.status"
                        @click="form.port_id = port.id"
                    >
                        {{ port.number }}
                        <span v-if="port.status === 'booking'" class="text-[10px] font-normal">booking</span>
                    </button>
                </div>
                <InputError :message="form.errors.port_id" />
            </form>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="emit('close')">Batal</Button>
                <Button v-if="chosenOdp" type="submit" form="move-form" :disabled="!form.port_id || form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    Pindahkan
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
