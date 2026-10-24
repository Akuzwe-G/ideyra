<?php

namespace App\Http\Controllers;

use App\Http\Requests\CvDocumentRequest;
use App\Models\CvDocument;
use App\Models\OpportunityApplication;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\CareerWorkflow;
use App\Support\OpportunityAccess;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CvController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:180']);
        $cvs = CvDocument::where('user_id', $request->user()->id)->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->string('q').'%'))->latest('updated_at')->paginate(12)->withQueryString();

        return view('career.cvs', compact('cvs'));
    }

    public function draftPreview(CvDocumentRequest $request): View
    {
        $payload = $request->validated();

        return view('career.cv-preview', compact('payload'));
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['template' => ['nullable', Rule::in(array_keys(config('career.templates')))]]);
        $template = $data['template'] ?? 'classic';
        $sections = match ($template) {
            'technical' => ['summary', 'skill', 'project', 'experience', 'education', 'certification', 'achievement', 'language', 'references'],'modern' => ['summary', 'education', 'project', 'skill', 'experience', 'achievement', 'certification', 'language', 'references'],default => array_keys(config('career.sections'))
        };
        $cv = new CvDocument(['title' => 'My professional CV', 'template' => $template, 'sections' => $sections, 'content' => array_fill_keys(['contact', ...array_keys(config('career.sections'))], '')]);

        return view('career.cv-editor', compact('cv'));
    }

    public function edit(CvDocument $cv): View
    {
        abort_unless(CareerAccess::own(auth()->user(), $cv->user_id), 403);

        return view('career.cv-editor', compact('cv'));
    }

    public function save(CvDocumentRequest $request, CareerWorkflow $workflow, ?CvDocument $cv = null): RedirectResponse
    {
        $cv = $workflow->saveCv($request->validated(), $cv);

        return redirect()->route('career.cvs.edit', $cv)->with('status', 'Private CV saved with a new immutable version.');
    }

    public function action(Request $request, CvDocument $cv, CareerWorkflow $workflow): RedirectResponse
    {
        abort_unless(CareerAccess::own($request->user(), $cv->user_id), 403);
        $data = $request->validate(['action' => 'required|in:duplicate,delete,import', 'confirm' => 'accepted']);
        if ($data['action'] === 'delete') {
            Audit::record('career.cv_deleted', $cv);
            $cv->delete();

            return redirect()->route('career.cvs.index')->with('status', 'CV deleted. Historical opportunity snapshots remain unchanged.');
        }
        $values = $cv->only(['title', 'template', 'sections', 'content']);
        if ($data['action'] === 'duplicate') {
            $values['title'] = mb_substr($cv->title.' (copy)', 0, 180);
            $cv = null;
        } else {
            $values['content'] = $workflow->importedContent($workflow->profile());
        }
        $saved = $workflow->saveCv($values, $cv);

        return redirect()->route('career.cvs.edit', $saved)->with('status', 'CV '.($data['action'] === 'import' ? 'imported from your profile. Restricted unapproved projects are excluded.' : 'duplicated.'));
    }

    public function preview(Request $request, CvDocument $cv): View|Response
    {
        abort_unless(CareerAccess::own($request->user(), $cv->user_id), 403);
        $request->validate(['version' => 'nullable|integer|min:1', 'pdf' => 'nullable|boolean']);
        $payload = $request->filled('version') ? $cv->snapshots()->where('version', $request->integer('version'))->firstOrFail()->payload : $cv->only(['title', 'template', 'sections', 'content']);

        return $this->render($payload, $request->boolean('pdf'));
    }

    public function application(Request $request, OpportunityApplication $application, int $version): View|Response
    {
        abort_unless(OpportunityAccess::applications($request->user())->whereKey($application->id)->exists(), 403);
        $snapshot = DB::table('application_snapshots')->where('application_id', $application->id)->where('version', $version)->first();
        abort_unless($snapshot, 404);
        $payload = json_decode(Crypt::decryptString($snapshot->payload), true);
        abort_unless(isset($payload['career_materials']['cv']), 404);

        return $this->render($payload['career_materials']['cv'], true);
    }

    private function render(array $payload, bool $pdf): View|Response
    {
        if ($pdf) {
            return Pdf::loadView('career.cv-pdf', ['payload' => $payload, 'pdf' => true])->setPaper('a4')
                ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print'])
                ->download('professional-cv.pdf')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return view('career.cv-preview', compact('payload'));
    }
}
