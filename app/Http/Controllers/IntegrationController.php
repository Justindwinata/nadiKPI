<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataImportBatch;
use App\Models\DataSource;
use App\Models\IntegrationProfile;
use App\Models\User;
use App\Services\IntegrationOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntegrationController extends Controller
{
    public function index(Request $request, IntegrationOnboardingService $service): JsonResponse
    {
        $user = $request->user();
        $datasets = collect($service->catalog())
            ->filter(fn (array $dataset) => $service->canView($user, $dataset['type']))
            ->values();
        $allowedTypes = $datasets->pluck('type');

        $batchScope = DataImportBatch::query()
            ->whereNotNull('dataset_type')
            ->whereIn('dataset_type', $allowedTypes);
        $summary = [
            'staged' => (clone $batchScope)->whereIn('status', ['staged', 'staged_with_errors', 'validated'])->count(),
            'published' => (clone $batchScope)->where('status', 'published')->count(),
            'exceptions' => (clone $batchScope)->whereIn('status', ['staged_with_errors', 'published_with_errors'])->count(),
            'rows_published' => (int) ((clone $batchScope)->sum('inserted_rows') + (clone $batchScope)->sum('updated_rows')),
        ];

        $batches = $batchScope
            ->with(['source:id,code,name,source_type,authority_rank', 'creator:id,name', 'publisher:id,name'])
            ->withCount([
                'stagingRows as invalid_rows_count' => fn ($query) => $query->whereIn('status', ['invalid', 'publish_error']),
                'stagingRows as published_rows_count' => fn ($query) => $query->where('status', 'published'),
            ])
            ->latest('imported_at')
            ->limit(80)
            ->get();

        return response()->json([
            'datasets' => $datasets,
            'sources' => DataSource::query()->where('is_active', true)->orderByDesc('authority_rank')->orderBy('name')->get(['id', 'code', 'name', 'source_type', 'authority_rank', 'owner_name']),
            'profiles' => IntegrationProfile::query()
                ->whereIn('dataset_type', $allowedTypes)
                ->where('is_active', true)
                ->with('source:id,code,name')
                ->orderBy('dataset_type')
                ->orderBy('name')
                ->get(),
            'batches' => $batches,
            'summary' => $summary,
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    public function template(Request $request, string $dataset, IntegrationOnboardingService $service): StreamedResponse
    {
        $this->authorizeDataset($request, $service, $dataset);
        [$headers, $sample] = $service->templateRows($dataset);

        return response()->streamDownload(function () use ($headers, $sample): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            fputcsv($output, $sample);
            fclose($output);
        }, "template-{$dataset}.csv", ['Content-Type' => 'text/csv']);
    }

    public function stage(Request $request, IntegrationOnboardingService $service): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'data_source_id' => ['required', 'integer', 'exists:data_sources,id'],
            'dataset_type' => ['required', 'string', 'max:80'],
            'dataset_name' => ['nullable', 'string', 'max:160'],
            'profile_id' => ['nullable', 'integer', 'exists:integration_profiles,id'],
            'column_mapping' => ['nullable'],
            'defaults' => ['nullable'],
        ]);
        $this->authorizeDataset($request, $service, $data['dataset_type']);

        $source = DataSource::findOrFail($data['data_source_id']);
        $mapping = $this->jsonArray($data['column_mapping'] ?? null, 'column_mapping');
        $defaults = $this->jsonArray($data['defaults'] ?? null, 'defaults');

        if (! empty($data['profile_id'])) {
            $profile = IntegrationProfile::findOrFail($data['profile_id']);
            if ((int) $profile->data_source_id !== (int) $source->id || $profile->dataset_type !== $data['dataset_type']) {
                throw ValidationException::withMessages(['profile_id' => 'Profile mapping tidak sesuai dengan sumber atau dataset yang dipilih.']);
            }
            $mapping = $mapping ?: $profile->column_mapping;
            $defaults = $defaults ?: ($profile->defaults ?? []);
        }

        $batch = $service->stage(
            $request->file('file'),
            $source,
            $data['dataset_type'],
            $request->user(),
            $mapping,
            $defaults,
            $data['dataset_name'] ?? null,
        );

        return response()->json(['batch' => $this->batchPayload($batch)], 201);
    }

    public function show(Request $request, DataImportBatch $batch, IntegrationOnboardingService $service): JsonResponse
    {
        $this->authorizeBatchView($request, $service, $batch);
        return response()->json(['batch' => $this->batchPayload($batch->load(['source', 'creator:id,name', 'publisher:id,name', 'stagingRows']))]);
    }

    public function updateMapping(Request $request, DataImportBatch $batch, IntegrationOnboardingService $service): JsonResponse
    {
        $this->authorizeBatch($request, $service, $batch);
        if ($batch->published_at) {
            throw ValidationException::withMessages(['batch' => 'Mapping batch yang sudah dipublikasikan tidak dapat diubah.']);
        }
        $data = $request->validate([
            'column_mapping' => ['required', 'array'],
            'column_mapping.*' => ['nullable', 'string', 'max:255'],
            'defaults' => ['nullable', 'array'],
        ]);
        $batch = DB::transaction(function () use ($request, $batch, $service, $data): DataImportBatch {
            $locked = DataImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $actor = $this->lockAuthorizedIntegrationActor($request, $service, (string) $locked->dataset_type);
            if ($locked->published_at) {
                throw ValidationException::withMessages(['batch' => 'Mapping batch yang sudah dipublikasikan tidak dapat diubah.']);
            }
            $updated = $service->revalidate($locked, $data['column_mapping'], $data['defaults'] ?? ($locked->mapping_defaults ?? []));
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'update_integration_mapping',
                'entity_type' => DataImportBatch::class,
                'entity_id' => $updated->id,
                'changes' => ['reference' => $updated->reference, 'mapping' => $data['column_mapping']],
                'ip_address' => $request->ip(),
            ]);
            return $updated;
        });

        return response()->json(['batch' => $this->batchPayload($batch)]);
    }

    public function publish(Request $request, DataImportBatch $batch, IntegrationOnboardingService $service): JsonResponse
    {
        $this->authorizeBatch($request, $service, $batch);
        $data = $request->validate(['allow_partial' => ['sometimes', 'boolean']]);
        $batch = $service->publish($batch, $request->user(), (bool) ($data['allow_partial'] ?? false));
        return response()->json(['batch' => $this->batchPayload($batch)]);
    }

    public function storeProfile(Request $request, IntegrationOnboardingService $service): JsonResponse
    {
        $data = $request->validate([
            'data_source_id' => ['required', 'integer', 'exists:data_sources,id'],
            'dataset_type' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160', Rule::unique('integration_profiles', 'name')->where(fn ($query) => $query->where('data_source_id', $request->input('data_source_id'))->where('dataset_type', $request->input('dataset_type')))],
            'column_mapping' => ['required', 'array'],
            'column_mapping.*' => ['nullable', 'string', 'max:255'],
            'defaults' => ['nullable', 'array'],
        ]);
        $this->authorizeDataset($request, $service, $data['dataset_type']);

        $profile = DB::transaction(function () use ($request, $data, $service): IntegrationProfile {
            $actor = $this->lockAuthorizedIntegrationActor($request, $service, $data['dataset_type']);
            $profile = IntegrationProfile::create([
                ...$data,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'create_integration_profile',
                'entity_type' => IntegrationProfile::class,
                'entity_id' => $profile->id,
                'changes' => ['name' => $profile->name, 'dataset_type' => $profile->dataset_type, 'data_source_id' => $profile->data_source_id],
                'ip_address' => $request->ip(),
            ]);
            return $profile;
        });

        return response()->json(['profile' => $profile->load('source:id,code,name')], 201);
    }

    public function updateProfile(Request $request, IntegrationProfile $profile, IntegrationOnboardingService $service): JsonResponse
    {
        $this->authorizeDataset($request, $service, $profile->dataset_type);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160', Rule::unique('integration_profiles', 'name')->where(fn ($query) => $query->where('data_source_id', $profile->data_source_id)->where('dataset_type', $profile->dataset_type))->ignore($profile->id)],
            'column_mapping' => ['sometimes', 'required', 'array'],
            'column_mapping.*' => ['nullable', 'string', 'max:255'],
            'defaults' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $profile = DB::transaction(function () use ($request, $profile, $data, $service): IntegrationProfile {
            $locked = IntegrationProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $actor = $this->lockAuthorizedIntegrationActor($request, $service, (string) $locked->dataset_type);
            $before = $locked->only(['name', 'column_mapping', 'defaults', 'is_active']);
            $locked->update($data);
            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'update_integration_profile',
                'entity_type' => IntegrationProfile::class,
                'entity_id' => $locked->id,
                'changes' => ['before' => $before, 'after' => $locked->fresh()->only(array_keys($before))],
                'ip_address' => $request->ip(),
            ]);
            return $locked->fresh('source:id,code,name');
        });

        return response()->json(['profile' => $profile]);
    }

    private function lockAuthorizedIntegrationActor(Request $request, IntegrationOnboardingService $service, string $datasetType): User
    {
        $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->is_active && $actor->hasPermission('integrations.manage') && $service->canManage($actor, $datasetType), 403, 'Hak onboarding dataset sudah berubah. Muat ulang sesi dan coba lagi.');

        return $actor;
    }

    private function authorizeDataset(Request $request, IntegrationOnboardingService $service, string $datasetType): void
    {
        if (! $request->user()->hasPermission('integrations.manage') || ! $service->canManage($request->user(), $datasetType)) {
            abort(403, 'Anda tidak memiliki kewenangan onboarding untuk dataset ini.');
        }
    }

    private function authorizeBatchView(Request $request, IntegrationOnboardingService $service, DataImportBatch $batch): void
    {
        if (! $batch->dataset_type) {
            abort(404);
        }
        if (! $request->user()->hasPermission('integrations.view') || ! $service->canView($request->user(), $batch->dataset_type)) {
            abort(403, 'Anda tidak memiliki akses baca untuk dataset ini.');
        }
    }

    private function authorizeBatch(Request $request, IntegrationOnboardingService $service, DataImportBatch $batch): void
    {
        if (! $batch->dataset_type) {
            abort(404);
        }
        $this->authorizeDataset($request, $service, $batch->dataset_type);
    }

    private function jsonArray(mixed $value, string $field): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([$field => 'Harus berupa object/array JSON yang valid.']);
        }
        return $decoded;
    }

    private function batchPayload(DataImportBatch $batch): array
    {
        $batch->loadMissing(['source:id,code,name,source_type,authority_rank', 'creator:id,name', 'publisher:id,name']);
        $rows = $batch->relationLoaded('stagingRows') ? $batch->stagingRows : $batch->stagingRows()->limit(50)->get();

        return [
            ...$batch->toArray(),
            'preview_rows' => $rows->take(50)->values(),
            'preview_truncated' => $batch->total_rows > 50,
        ];
    }
}
