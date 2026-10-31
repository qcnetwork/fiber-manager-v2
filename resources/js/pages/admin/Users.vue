<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Role, SharedData, User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    users: User[];
    areas: { id: number; code: string; name: string }[];
    roles: { value: Role; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pengguna', href: '/admin/users' }];
const me = usePage<SharedData>().props.auth.user;
const roleLabel = (role: Role) => props.roles.find((r) => r.value === role)?.label ?? role;

const editing = ref<User | null>(null);
const form = useForm({ name: '', username: '', email: '', role: 'teknisi' as Role, area_id: null as number | null, password: '' });

function edit(user: User) {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.username = user.username ?? '';
    form.email = user.email ?? '';
    form.role = user.role;
    form.area_id = user.area_id;
    form.password = '';
}

function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value) form.put(route('admin.users.update', { user: editing.value.id }), options);
    else form.post(route('admin.users.store'), options);
}

function destroy(user: User) {
    if (confirm(`Hapus pengguna ${user.name}?`)) router.delete(route('admin.users.destroy', { user: user.id }), { preserveScroll: true });
}
</script>

<template>
    <Head title="Pengguna" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid flex-1 items-start gap-4 p-4 lg:grid-cols-[1fr_22rem]">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Daftar pengguna</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">Nama</th>
                                <th class="py-2 font-medium">Role</th>
                                <th class="py-2 font-medium">Area</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="user in users" :key="user.id">
                                <td class="py-2">
                                    <div class="font-medium">{{ user.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ [user.username, user.email].filter(Boolean).join(' · ') }}</div>
                                </td>
                                <td class="py-2">{{ roleLabel(user.role) }}</td>
                                <td class="py-2">{{ user.area?.name ?? 'Semua area' }}</td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <Button variant="ghost" size="icon" title="Edit" @click="edit(user)"><Pencil class="size-4" /></Button>
                                    <Button v-if="user.id !== me.id" variant="ghost" size="icon" title="Hapus" @click="destroy(user)">
                                        <Trash2 class="size-4 text-red-600" />
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">{{ editing ? `Edit ${editing.name}` : 'Tambah pengguna' }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div class="grid gap-1.5">
                            <Label for="name">Nama</Label>
                            <Input id="name" v-model="form.name" required />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="username">Username</Label>
                            <Input id="username" v-model="form.username" autocomplete="off" />
                            <InputError :message="form.errors.username" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="email">Email {{ form.username ? '(opsional)' : '' }}</Label>
                            <Input id="email" v-model="form.email" type="email" :required="!form.username" />
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="role">Role</Label>
                            <NativeSelect id="role" v-model="form.role">
                                <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                            </NativeSelect>
                            <InputError :message="form.errors.role" />
                        </div>
                        <div v-if="form.role !== 'super_admin'" class="grid gap-1.5">
                            <Label for="area_id">Area</Label>
                            <NativeSelect id="area_id" v-model="form.area_id">
                                <option :value="null">Semua area</option>
                                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }} ({{ area.code }})</option>
                            </NativeSelect>
                            <InputError :message="form.errors.area_id" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="password">{{ editing ? 'Password baru (kosongkan jika tidak diubah)' : 'Password' }}</Label>
                            <Input id="password" v-model="form.password" type="password" autocomplete="new-password" :required="!editing" />
                            <InputError :message="form.errors.password" />
                        </div>
                        <div class="flex gap-2">
                            <Button type="submit" class="flex-1" :disabled="form.processing">Simpan</Button>
                            <Button v-if="editing" type="button" variant="outline" @click="reset">Batal</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
