import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, type InjectionKey, type Ref } from 'vue';

/**
 * Fills a form with the values of a change request an approver is editing; only fields the form has.
 */
export function applyInitial<T extends object>(form: T, initial: Record<string, unknown> | null | undefined) {
    if (!initial) return;
    const target = form as Record<string, unknown>;
    for (const [key, value] of Object.entries(initial)) {
        if (key in target && key !== 'change_request_id') target[key] = value ?? (typeof target[key] === 'string' ? '' : value);
    }
}

/**
 * Technicians' changes become requests for an admin to approve; their buttons say so.
 */
export function useApproval() {
    const page = usePage<SharedData>();
    const needsApproval = computed(() => page.props.auth.needsApproval);

    return {
        needsApproval,
        saveLabel: computed(() => (needsApproval.value ? 'Simpan draft' : 'Simpan')),
        deleteLabel: computed(() => (needsApproval.value ? 'Hapus (draft)' : 'Hapus')),
    };
}

/** Photos a technician's drafts change, by photo id: "updated" or "deleted". Provided by the map. */
export const PHOTO_DRAFTS: InjectionKey<Ref<Map<number, string>>> = Symbol('photoDrafts');
