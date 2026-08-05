<?php

namespace App\Http\Controllers;

use App\Http\Requests\Baselines\StoreBaselineDocumentRequest;
use App\Models\Baseline;
use App\Models\BaselineDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BaselineDocumentController extends Controller
{
    /**
     * Attach a contract document to the draft baseline (wizard step 2).
     * Files land on the private local disk — internal-only until baseline
     * approval shares them through the portal.
     */
    public function store(StoreBaselineDocumentRequest $request, Baseline $baseline): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('document');

        $baseline->mutateAsDraft(function () use ($request, $baseline, $file): void {
            $path = $file->store("baselines/{$baseline->id}/contracts", 'local');

            if ($path === false) {
                abort(500, 'The contract document could not be stored.');
            }

            $baseline->documents()->create([
                'organization_id' => $baseline->organization_id,
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $request->user()?->id,
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':filename uploaded.', [
            'filename' => $file->getClientOriginalName(),
        ])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }

    /**
     * Download a contract document. Internal users only — the portal reads
     * shared documents through its own routes after approval.
     */
    public function show(Request $request, Baseline $baseline, BaselineDocument $document): StreamedResponse
    {
        Gate::authorize('view', $baseline);

        return Storage::disk('local')->download($document->path, $document->filename);
    }

    /**
     * Remove a contract document from the draft baseline; the stored file is
     * deleted with it.
     */
    public function destroy(Request $request, Baseline $baseline, BaselineDocument $document): RedirectResponse
    {
        Gate::authorize('update', $baseline);

        $baseline->mutateAsDraft(fn () => $document->delete());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':filename removed.', [
            'filename' => $document->filename,
        ])]);

        return to_route('engagements.baseline.show', $baseline->engagement_id);
    }
}
