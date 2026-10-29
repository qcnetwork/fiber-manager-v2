<?php

namespace App\Http\Controllers;

use App\Enums\CableType;
use App\Models\Area;
use App\Services\Transfer\ExcelExporter;
use App\Services\Transfer\ImportException;
use App\Services\Transfer\KmlExporter;
use App\Services\Transfer\KmlReader;
use App\Services\Transfer\NetworkImporter;
use App\Services\Transfer\SheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataTransferController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('DataTransfer', [
            'areas' => $user->seesAllAreas() ? Area::orderBy('name')->get(['id', 'code', 'name']) : [],
            'can' => [
                'import' => $user->canDeleteNetwork(),
                'chooseArea' => $user->seesAllAreas(),
            ],
            'maxRows' => NetworkImporter::MAX_ROWS,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['xlsx', 'kml'], true), 404);

        // Non-super users are limited to their area by the model scope; area_id only narrows it further.
        $areaId = $request->user()->seesAllAreas() ? $request->integer('area_id') ?: null : null;
        $area = $areaId ? Area::findOrFail($areaId) : $request->user()->area;
        $label = $area ? Str::slug($area->code) : 'semua-area';
        $filename = "data-jaringan-{$label}-".now()->format('Ymd-His').".{$format}";

        return response()->streamDownload(function () use ($format, $areaId, $area) {
            if ($format === 'xlsx') {
                app(ExcelExporter::class)->export($areaId, 'php://output');
            } else {
                app(KmlExporter::class)->export($areaId, 'php://output', 'Jaringan '.($area->name ?? 'semua area'));
            }
        }, $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'application/vnd.google-earth.kml+xml',
        ]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => app(ExcelExporter::class)->template('php://output'),
            'template-impor-odc-odp.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function import(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canDeleteNetwork(), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(KmlReader::MAX_BYTES / 1024), 'extensions:xlsx,kml,kmz'],
            'area_id' => $user->seesAllAreas() ? ['required', 'exists:areas,id'] : ['exclude'],
            'point_mode' => ['nullable', Rule::in(['auto', 'odc', 'odp'])],
            'cable_type' => ['nullable', Rule::enum(CableType::class)],
            'import_lines' => ['nullable', 'boolean'],
        ], [
            'file.extensions' => 'File harus berformat .xlsx, .kml, atau .kmz.',
        ]);

        $file = $request->file('file');
        $areaId = $user->seesAllAreas() ? (int) $data['area_id'] : (int) $user->area_id;
        $importer = new NetworkImporter($areaId);

        try {
            if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
                $rows = app(SheetReader::class)->read($file->getRealPath());
                $result = $importer->import($rows['odc'], $rows['odp']);
            } else {
                $result = $this->importKml($importer, $file->getRealPath(), $file->getClientOriginalName(), $data);
            }
        } catch (ImportException $e) {
            $result = ['ok' => false, 'created' => [], 'updated' => [], 'skipped' => [], 'errors' => [['where' => 'File', 'message' => $e->getMessage()]]];
        }

        return back()->with('importResult', [...$result, 'file' => $file->getClientOriginalName()]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function importKml(NetworkImporter $importer, string $path, string $name, array $options): array
    {
        $kml = app(KmlReader::class)->read($path, $name);
        $mode = $options['point_mode'] ?? 'auto';
        $rows = ['odc' => [], 'odp' => []];
        $skipped = [];

        foreach ($kml['points'] as $point) {
            $kind = $mode === 'auto' ? $point['kind'] : $mode;
            if ($kind === null) {
                $skipped[] = ['where' => $point['label'], 'message' => 'Bukan ODC/ODP (nama atau folder tidak mengandung "ODC"/"ODP").'];

                continue;
            }
            $rows[$kind][] = $point;
        }

        $cables = [];
        if ($options['import_lines'] ?? true) {
            foreach ($kml['lines'] as $line) {
                $cables[] = [
                    'label' => $line['label'],
                    'data' => [...$line['data'], 'jenis' => $line['type'] ?? $options['cable_type'] ?? CableType::Distribusi->value],
                ];
            }
        }

        return $importer->import($rows['odc'], $rows['odp'], $cables, $skipped);
    }
}
