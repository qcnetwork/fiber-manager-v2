<script setup lang="ts">
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Cable,
    Camera,
    ClipboardCheck,
    FilePen,
    LifeBuoy,
    Map,
    MapPin,
    Pencil,
    Plug,
    Radar,
    Search,
    Smartphone,
    TriangleAlert,
    type LucideIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bantuan', href: '/bantuan' }];

const page = usePage<SharedData>();
const canApprove = computed(() => page.props.auth.canApprove);

interface Section {
    id: string;
    title: string;
    icon: LucideIcon;
    /** A short line under the title. */
    intro?: string;
    /** Numbered steps. */
    steps?: string[];
    /** Loose points. */
    points?: string[];
    /** A highlighted remark. */
    note?: string;
    /** Only for admins who approve. */
    approverOnly?: boolean;
}

const sections: Section[] = [
    {
        id: 'mulai',
        title: 'Mulai',
        icon: Smartphone,
        points: [
            'Masuk dengan username (atau email) dan password dari admin.',
            'Di HP, pakai menu **Install aplikasi** di bawah menu samping agar aplikasi bisa dibuka seperti aplikasi biasa.',
            'Menu utama teknisi: **Peta Jaringan** (pekerjaan sehari-hari), **Dashboard**, **Cek Coverage**, **Ekspor & Impor**, dan **Request Saya** (status perubahan yang dikirim).',
            'Izinkan akses lokasi di browser supaya tombol GPS / Lokasi saya bisa dipakai.',
        ],
    },
    {
        id: 'draft',
        title: 'Cara kerja draft & approval',
        icon: FilePen,
        intro: 'Semua perubahan dari akun teknisi tidak langsung masuk ke data jaringan. Perubahan disimpan sebagai draft dulu, lalu dikirim sekaligus untuk disetujui admin.',
        steps: [
            'Kerjakan semua perubahan seperti biasa: tambah ODC/ODP/joint, gambar kabel, edit, hapus, isi port, foto. Tombol simpan bertuliskan **Simpan draft**.',
            'Draft langsung tampil di peta: aset baru tampak **samar** dengan label DRAFT, kabel baru **putus-putus**, aset yang diubah/dihapus diberi **cincin oranye/merah**.',
            'Draft boleh saling terhubung. Contoh: buat ODC baru, lalu ODP di port ODC itu, lalu kabel dari ODC ke ODP tersebut — semuanya masih draft.',
            'Bar **Perubahan belum dikirim** di bawah peta berisi daftar draft. Klik salah satu untuk terbang ke lokasinya, lalu **Edit draft** atau **Hapus draft**.',
            'Setelah semua selesai, buka bar draft, isi catatan untuk admin bila perlu, lalu tekan **Kirim ke approval**. Semua draft terkirim sebagai satu **paket**.',
            'Pantau hasilnya di menu **Request Saya**. Lonceng di kanan atas memberi tanda bila paket sudah diputuskan.',
        ],
        points: [
            'Draft tersimpan di server: aman bila HP ditutup dan bisa dilanjutkan dari perangkat lain. Hanya kamu yang melihat draft milikmu.',
            'Satu paket disetujui atau ditolak **seluruhnya**. Bila satu perubahan sudah tidak cocok (misalnya kodenya keburu dipakai orang lain), tidak ada yang tersimpan dan admin melihat alasannya.',
            'Paket yang masih menunggu bisa kamu batalkan di Request Saya (**Batalkan paket**).',
            'Bila paket ditolak, baca catatan admin, perbaiki, lalu buat dan kirim ulang.',
        ],
        note: 'Data jaringan baru berubah setelah paket disetujui. Sebelum itu, pengguna lain tidak melihat perubahanmu.',
    },
    {
        id: 'peta',
        title: 'Menggunakan peta',
        icon: Map,
        points: [
            '**Cari** dengan kode, nama, alamat, catatan, koordinat (mis. -7.0429, 109.5813), atau tempel link Google Maps.',
            '**Lokasi saya** memusatkan peta ke posisimu. **Layar penuh** dan tombol **sembunyikan menu** memberi ruang peta lebih luas di HP.',
            'Tombol lapisan (kanan atas) untuk ganti **Peta / Satelit** dan menampilkan/menyembunyikan kabel, ODC, ODP, POP, joint, dan pelanggan. Pelanggan baru muncul saat peta cukup diperbesar.',
            'Saring dengan **Semua status** (Aktif / Rencana / Rusak).',
            'Warna ODP menunjukkan okupansi: hijau < 50%, kuning ≥ 50%, merah penuh, abu-abu rencana, ungu rusak. Lingkaran di sekitar ODP = port terpakai.',
            'Klik aset untuk membuka panelnya: detail, port, sambungan core, foto, **Navigasi** (buka rute di Google Maps), **Edit**, dan **Hapus**.',
        ],
    },
    {
        id: 'aset',
        title: 'Menambah ODC, ODP, atau joint/roset',
        icon: MapPin,
        steps: [
            'Tekan tombol **ODC**, **ODP**, atau **Joint/Roset** di atas peta.',
            'Tentukan lokasi: klik peta, geser penanda merah, tekan **Pakai GPS**, atau ketik koordinat.',
            'Isi kode dan data lainnya. Untuk ODP, pilih **ODC induk** dan **Port ODC** (ODC draft juga muncul di daftar dengan tanda "(draft)").',
            'Bila ada kabel dalam radius 30 m, pilih kabelnya di bagian **Sambungkan ke kabel terdekat** agar aset tercatat di jalur kabel itu.',
            'Tekan **Simpan draft**.',
        ],
    },
    {
        id: 'kabel',
        title: 'Menggambar kabel',
        icon: Cable,
        steps: [
            'Tekan tombol **Kabel**, lalu klik peta titik demi titik mengikuti jalan/tiang.',
            'Klik di dekat POP/ODC/ODP/joint: pilih **disambung ke aset itu** atau jadi titik biasa. Aset di tengah jalur tercatat sebagai titik sambung yang dilewati.',
            'Geser titik untuk memindahkan, klik titik untuk menghapusnya. Untuk menambah titik di tengah, geser kotak samar di tengah tiap ruas. Ada tombol **Undo** dan **Ulang dari awal**.',
            'Isi kode, jenis (feeder/distribusi/drop), jumlah core, dan isi core per tube, lalu **Simpan draft**.',
        ],
        points: ['Kabel boleh berujung di ODC/ODP/joint yang masih draft.', 'Ujung kabel ikut berpindah bila aset di ujungnya dipindah.'],
    },
    {
        id: 'edit',
        title: 'Mengubah dan menghapus',
        icon: Pencil,
        points: [
            'Buka aset di peta → **Edit** → ubah data atau lokasi → **Simpan draft**.',
            '**Hapus (draft)** mengajukan penghapusan. Aset tetap ada sampai paketnya disetujui, dan ditandai merah di peta.',
            'Draft milikmu sendiri bisa diubah lagi lewat **Edit draft** di panel draft.',
            'Draft yang dipakai draft lain (misalnya ODC draft yang punya ODP draft) tidak bisa dihapus sendirian. Hapus dulu draft yang memakainya.',
        ],
    },
    {
        id: 'port',
        title: 'Port ODP dan pelanggan',
        icon: Plug,
        points: [
            'Di panel ODP, klik nomor port untuk mengubah status port (kosong/terpakai/rusak) dan data pelanggan yang terhubung.',
            'Lokasi pelanggan bisa langsung diisi di form port: tombol **GPS** di rumah pelanggan, atau tempel koordinat / link Google Maps. Foto pelanggan ditambahkan dari panel pelanggan. **Pindah ODP/port** untuk memindahkan pelanggan ke port lain.',
            'Port dan sambungan core pada ODP/kabel yang masih draft baru bisa diisi setelah paketnya disetujui.',
            '**Splice sheet** di panel kabel menampilkan daftar sambungan core yang bisa dicetak.',
        ],
    },
    {
        id: 'foto',
        title: 'Foto aset',
        icon: Camera,
        points: [
            '**Tambah foto** membuka kamera atau galeri. Foto otomatis diperkecil sebelum dikirim (maks. 8 MB, JPG/PNG/WebP).',
            'Di komputer, foto bisa ditempel dengan **Ctrl+V**. Di HP, pakai tombol tempel di samping Tambah foto (bila browser mendukung).',
            'Klik foto untuk membesarkannya. **Edit / ganti** untuk mengubah keterangan atau mengganti fotonya. **Hapus (draft)** untuk mengajukan penghapusan.',
            'Foto yang punya draft ditandai bingkai oranye dengan label **Draft edit** atau **Draft hapus**.',
            'Foto juga bisa ditambahkan ke aset yang masih draft. Foto itu ikut tersimpan saat paket disetujui.',
        ],
    },
    {
        id: 'terdekat',
        title: 'Cari ODP terdekat & cek coverage',
        icon: Radar,
        points: [
            'Tombol **Terdekat** di peta: klik lokasi calon pelanggan, tempel koordinat/link Google Maps, atau pakai GPS. Hanya ODP dengan port kosong yang ditampilkan, sesuai radius yang dipilih.',
            'Menu **Cek Coverage** untuk mengecek apakah suatu alamat/lokasi masuk jangkauan jaringan.',
        ],
    },
    {
        id: 'masalah',
        title: 'Masalah yang sering terjadi',
        icon: TriangleAlert,
        points: [
            '**"Draft lain sudah tidak valid"**: ada data yang berubah sejak draft dibuat (misalnya port ODC sudah dipakai). Edit atau hapus draft yang disebut.',
            '**"Tidak ada perubahan untuk disimpan"**: isian sama dengan data sekarang, jadi tidak dibuat draft.',
            '**Tanda konflik kuning** di draft: data aslinya sudah diubah orang lain setelah draftmu dibuat. Periksa lagi sebelum mengirim.',
            '**GPS tidak jalan**: izinkan akses lokasi untuk situs ini di pengaturan browser, dan nyalakan lokasi HP.',
            '**Draft tidak muncul**: pastikan masuk dengan akun yang sama. Draft hanya terlihat oleh pembuatnya.',
        ],
    },
    {
        id: 'admin',
        title: 'Untuk admin: menyetujui paket',
        icon: ClipboardCheck,
        approverOnly: true,
        points: [
            'Menu **Approval** menampilkan paket yang menunggu: pengaju, catatan, dan daftar perubahan (klik untuk rincian, foto lama → baru).',
            '**Lihat paket di peta** menampilkan semua perubahan paket di peta tanpa menerapkannya.',
            '**Edit** per item mengubah isi perubahan sebelum disetujui. **Setujui semua** menerapkan seluruh paket, **Tolak** dengan catatan untuk teknisi.',
            'Admin area hanya memproses paket di areanya. Paket lintas area hanya bisa diproses super admin.',
        ],
    },
];

const query = ref('');
const plain = (text: string) => text.replace(/\*\*/g, '');

const visible = computed(() => {
    const q = query.value.trim().toLowerCase();
    return sections
        .filter((s) => !s.approverOnly || canApprove.value)
        .filter((s) => {
            if (!q) return true;
            const text = [s.title, s.intro, s.note, ...(s.steps ?? []), ...(s.points ?? [])].filter(Boolean).join(' ');
            return plain(text).toLowerCase().includes(q);
        });
});

/** Bold markers (**text**) as HTML; the texts are fixed strings in this file. */
const rich = (text: string) =>
    text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-foreground">$1</strong>');
</script>

<template>
    <Head title="Bantuan" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
            <div class="flex items-start gap-3">
                <LifeBuoy class="mt-1 size-6 shrink-0 text-primary" />
                <div>
                    <h1 class="text-xl font-semibold">Panduan teknisi</h1>
                    <p class="text-sm text-muted-foreground">
                        Cara mencatat jaringan di peta, menyimpan draft, dan mengirimnya untuk disetujui admin.
                    </p>
                </div>
            </div>

            <div class="relative">
                <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="query" type="search" placeholder="Cari di panduan, mis. foto, kabel, GPS…" class="h-10 pl-9" />
            </div>

            <nav v-if="!query" class="flex flex-wrap gap-1.5" aria-label="Isi panduan">
                <a
                    v-for="s in visible"
                    :key="s.id"
                    :href="`#${s.id}`"
                    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs hover:bg-muted"
                >
                    <component :is="s.icon" class="size-3.5" /> {{ s.title }}
                </a>
            </nav>

            <p v-if="!visible.length" class="rounded-md border px-4 py-6 text-center text-sm text-muted-foreground">
                Tidak ada bagian panduan yang cocok dengan "{{ query }}".
            </p>

            <section v-for="s in visible" :id="s.id" :key="s.id" class="scroll-mt-4 rounded-xl border p-4 sm:p-5">
                <h2 class="flex items-center gap-2 text-base font-semibold"><component :is="s.icon" class="size-5 text-primary" /> {{ s.title }}</h2>
                <p v-if="s.intro" class="mt-2 text-sm text-muted-foreground">{{ s.intro }}</p>

                <ol v-if="s.steps" class="mt-3 flex flex-col gap-2">
                    <li v-for="(step, i) in s.steps" :key="i" class="flex gap-3 text-sm text-muted-foreground">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground tabular-nums"
                        >
                            {{ i + 1 }}
                        </span>
                        <!-- eslint-disable-next-line vue/no-v-html -- fixed help texts from this file -->
                        <span class="pt-0.5" v-html="rich(step)" />
                    </li>
                </ol>

                <ul v-if="s.points" class="mt-3 flex list-disc flex-col gap-1.5 pl-5 text-sm text-muted-foreground marker:text-primary">
                    <!-- eslint-disable-next-line vue/no-v-html -- fixed help texts from this file -->
                    <li v-for="(point, i) in s.points" :key="i" v-html="rich(point)" />
                </ul>

                <p
                    v-if="s.note"
                    class="mt-3 rounded-md border border-orange-300 bg-orange-50 px-3 py-2 text-sm text-orange-900 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200"
                >
                    {{ s.note }}
                </p>
            </section>

            <p class="pb-4 text-center text-xs text-muted-foreground">
                Masih bingung? Hubungi admin area kamu. Kembali ke <Link :href="route('map')" class="underline">Peta Jaringan</Link>.
            </p>
        </div>
    </AppLayout>
</template>
