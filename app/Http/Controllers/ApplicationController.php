<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinApplicationRequest;
use App\Models\JoinApplication;
use App\Support\ApplicationSchema;
use App\Support\ApplicationWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(): View
    {
        return view('applications.index');
    }

    public function form(string $type): View
    {
        abort_unless(json_decode(DB::table('platform_settings')->where('key', 'applications_open')->value('value') ?? 'true'), 503, 'Applications are temporarily closed.');
        $existingNonce = session('application_nonce.'.$type);
        if (! $existingNonce || JoinApplication::where('submission_hash', ApplicationWorkflow::hash($existingNonce))->exists()) {
            session()->put('application_nonce.'.$type, bin2hex(random_bytes(32)));
        }

        return view('applications.form', ['type' => $type, 'sections' => ApplicationSchema::sections($type)]);
    }

    public function store(JoinApplicationRequest $request, string $type, ApplicationWorkflow $workflow): RedirectResponse
    {
        abort_unless(json_decode(DB::table('platform_settings')->where('key', 'applications_open')->value('value') ?? 'true'), 503);
        try {
            $application = $workflow->submit($type, $request->validated());
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return back()->withErrors(['submission' => 'This submission was already recorded. Contact the team if you need receipt assistance.']);
        }

        return redirect($workflow->receiptUrl($application));
    }

    public function receipt(JoinApplication $application, Request $request): Response
    {
        return response()->view('applications.receipt', compact('application'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex');
    }

    public function pdf(JoinApplication $application): Response
    {
        return Pdf::loadView('applications.pdf', ['application' => $application, 'sections' => ApplicationSchema::sections($application->type)])
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->download($application->reference.'.pdf')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
