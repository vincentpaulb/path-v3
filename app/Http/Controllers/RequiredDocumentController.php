<?php

namespace App\Http\Controllers;

use App\Models\RequiredDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequiredDocumentController extends Controller
{
    public function index()
    {
        return view('required-documents.index', [
            // Keep the default process order and append newly created items at the end.
            'documents' => RequiredDocument::query()->orderBy('id')->paginate(8),
            'totalDocuments' => RequiredDocument::query()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:500',
                Rule::unique('required_documents', 'name'),
            ],
        ]);

        RequiredDocument::create($data);

        return back()->with('success', 'Required document added. It will now appear in future document-review checklists.');
    }

    public function update(Request $request, RequiredDocument $requiredDocument)
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:500',
                Rule::unique('required_documents', 'name')->ignore($requiredDocument->id),
            ],
        ]);

        $oldName = $requiredDocument->name;
        $requiredDocument->update($data);

        return back()->with(
            'success',
            "Required document updated from '{$oldName}' to '{$requiredDocument->name}'. The updated name is now used by the proposal document-review checklist."
        );
    }

    public function destroy(Request $request, RequiredDocument $requiredDocument)
    {
        $name = $requiredDocument->name;
        $requiredDocument->forceFill(['deleted_by' => $request->user()->id])->save();
        $requiredDocument->delete();

        return back()->with('success', "Required document moved to Archives: {$name}. Existing proposal review history is unchanged.");
    }
}
