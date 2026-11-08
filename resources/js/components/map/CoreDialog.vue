<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { coreInfo, coreLabel, coreStatuses } from '@/lib/cores';
import type { Cable, CableCore, CoreStatus } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, watch } from 'vue';

const props = defineProps<{
    cable: Cable;
    core: CableCore | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    saved: [];
    close: [];
}>();

const form = useForm({ status: 'kosong' as CoreStatus, notes: '' });

watch(
    () => props.core,
    (core) => {
        if (!core) return;
        form.status = core.status;
        form.notes = core.notes ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

const info = computed(() => (props.core ? coreInfo(props.core.number, props.cable.tube_size) : null));
const cableName = computed(() => props.cable.code ?? `Kabel #${props.cable.id}`);

function submit() {
    if (!props.core) return;
    form.patch(route('cable-cores.update', { core: props.core.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <Dialog :open="!!core" @update:open="(open) => !open && emit('close')">
        <DialogContent v-if="core && info" class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span class="core-dot size-4" :style="{ '--c': info.color.hex }" :class="info.striped && 'is-striped'" />
                    Core {{ core.number }} — {{ cableName }}
                </DialogTitle>
                <DialogDescription>{{ coreLabel(core.number, cable.tube_size, cable.cores) }}</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label>Status</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="(s, key) in coreStatuses"
                            :key="key"
                            type="button"
                            class="rounded-md border px-2 py-1.5 text-sm"
                            :class="form.status === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            :disabled="!canEdit"
                            @click="form.status = key"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                    <InputError :message="form.errors.status" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="core_notes">Keterangan (dipakai untuk apa)</Label>
                    <Input id="core_notes" v-model="form.notes" :disabled="!canEdit" placeholder="mis. Feeder ke ODC-03 port 2" />
                    <InputError :message="form.errors.notes" />
                </div>

                <DialogFooter v-if="canEdit" class="gap-2">
                    <Button type="button" variant="outline" @click="emit('close')">Batal</Button>
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Simpan
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
