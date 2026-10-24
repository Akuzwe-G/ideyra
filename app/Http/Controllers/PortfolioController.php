<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortfolioContactRequest;
use App\Http\Requests\PortfolioRequest;
use App\Models\Portfolio;
use App\Models\PortfolioMedia;
use App\Models\PortfolioMessage;
use App\Notifications\WorkspaceNotification;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\CareerWorkflow;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortfolioController extends Controller
{
    public function edit(CareerWorkflow $workflow): View
    {
        $portfolio = $workflow->portfolio();
        $profile = $portfolio->profile;
        $entries = $profile->entries;
        $showcases = $profile->showcases()->with('project')->get()->filter(fn ($s) => CareerAccess::projectContent($s) !== null);

        $messages = $portfolio->messages()->paginate(10);

        return view('career.portfolio-builder', compact('portfolio', 'profile', 'entries', 'showcases', 'messages'));
    }

    public function save(PortfolioRequest $request, CareerWorkflow $workflow): RedirectResponse
    {
        $portfolio = $workflow->portfolio();
        $data = $request->validated();
        $entryIds = array_map('intval', $data['entry_ids'] ?? []);
        $showcaseIds = array_map('intval', $data['showcase_ids'] ?? []);
        $data['entry_ids'] = $entryIds;
        $data['showcase_ids'] = $showcaseIds;
        $data['sections'] = array_values($data['sections'] ?? []);
        $data['contact_fields'] = array_values($data['contact_fields'] ?? []);
        abort_unless(count($entryIds) === $portfolio->profile->entries()->whereIn('id', $entryIds)->count(), 422);
        abort_unless(count($showcaseIds) === $portfolio->profile->showcases()->whereIn('id', $showcaseIds)->count(), 422);
        $workflow->publish($portfolio, false);
        $portfolio->update($data + ['sections' => [], 'entry_ids' => [], 'showcase_ids' => [], 'contact_fields' => [], 'search_visible' => false, 'contact_form_enabled' => false]);

        return redirect()->route('career.portfolio')->with('status', 'Portfolio settings saved privately. Preview and explicitly publish when ready.');
    }

    public function publish(Request $request, CareerWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['action' => 'required|in:publish,unpublish', 'confirm' => 'accepted']);
        $workflow->publish($workflow->portfolio(), $data['action'] === 'publish');

        return redirect()->route('career.portfolio')->with('status', $data['action'] === 'publish' ? 'Portfolio published. Only explicitly selected information is public.' : 'Portfolio unpublished.');
    }

    public function contact(PortfolioContactRequest $request, string $slug): RedirectResponse
    {
        $portfolio = Portfolio::where('slug', $slug)->where('published', true)->where('contact_form_enabled', true)->whereHas('profile.user', fn ($q) => $q->where('status', 'active'))->firstOrFail();
        $data = $request->validated();
        try {
            $token = json_decode(Crypt::decryptString($data['form_token']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['form_token' => 'Refresh this page and try the contact form again.']);
        }
        if (! is_array($token) || ($token['portfolio_id'] ?? null) !== $portfolio->id || ! is_int($token['issued_at'] ?? null)
            || now()->timestamp - $token['issued_at'] < 2 || now()->timestamp - $token['issued_at'] > 7200 || ! is_string($token['nonce'] ?? null) || strlen($token['nonce']) !== 32) {
            throw ValidationException::withMessages(['form_token' => 'Refresh this page, take a moment to complete the form, and try again.']);
        }
        DB::transaction(function () use ($portfolio, $data, $token): void {
            $message = $portfolio->messages()->firstOrCreate(['submission_key' => hash('sha256', $portfolio->id.':'.$token['nonce'])], collect($data)->only(['sender_name', 'sender_email', 'message'])->all());
            if ($message->wasRecentlyCreated) {
                $portfolio->profile->user->notifyNow(new WorkspaceNotification(['title' => 'New portfolio contact', 'message' => 'A visitor sent a message to your private portfolio inbox.', 'target' => 'career_portfolio', 'subject_id' => $portfolio->id]));
                Audit::record('career.contact_received', $message);
            }
        });

        return redirect()->route('portfolio.public', $slug)->with('status', 'Message saved in the portfolio owner’s private Ideyra inbox. External email delivery is not enabled.');
    }

    public function messageAction(Request $request, PortfolioMessage $message): RedirectResponse
    {
        abort_unless(CareerAccess::own($request->user(), $message->portfolio->profile->user_id), 403);
        $data = $request->validate(['action' => 'required|in:read,delete', 'confirm' => 'accepted']);
        Audit::record('career.contact_'.$data['action'], $message);
        if ($data['action'] === 'delete') {
            $message->delete();
        } else {
            $message->update(['read_at' => now()]);
        }

        return redirect()->route('career.portfolio')->with('status', 'Private inbox updated.');
    }

    public function preview(CareerWorkflow $workflow): View
    {
        $portfolio = $workflow->portfolio();
        $payload = $workflow->portfolioPayload($portfolio);
        $preview = true;

        return view('career.portfolio-public', compact('portfolio', 'payload', 'preview'));
    }

    public function show(string $slug, CareerWorkflow $workflow): Response
    {
        $portfolio = Portfolio::where('slug', $slug)->where('published', true)->whereHas('profile.user', fn ($q) => $q->where('status', 'active'))->firstOrFail();
        $payload = $workflow->visiblePayload($portfolio);

        return response()->view('career.portfolio-public', ['portfolio' => $portfolio, 'payload' => $payload, 'preview' => false])
            ->header('X-Robots-Tag', $portfolio->search_visible ? 'index, follow' : 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }

    public function media(string $slug, PortfolioMedia $media): StreamedResponse
    {
        $portfolio = Portfolio::where('slug', $slug)->where('published', true)->whereHas('profile.user', fn ($q) => $q->where('status', 'active'))->firstOrFail();
        abort_unless($media->public_approved && $media->showcase->professional_profile_id === $portfolio->professional_profile_id
            && in_array($media->id, $portfolio->payload['media_ids'] ?? [], true)
            && in_array('project', $portfolio->sections, true)
            && collect(app(CareerWorkflow::class)->visiblePayload($portfolio)['projects'])->contains(fn ($p) => $p['showcase_id'] === $media->project_showcase_id), 404);

        return Storage::disk('local')->response($media->path, null, ['Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }

    public function photo(Request $request, ?string $slug = null): StreamedResponse
    {
        if ($slug) {
            $portfolio = Portfolio::where('slug', $slug)->where('published', true)->whereHas('profile.user', fn ($q) => $q->where('status', 'active'))->firstOrFail();
            $payload = app(CareerWorkflow::class)->visiblePayload($portfolio);
            $path = $payload['contact']['photo_path'] ?? null;
        } else {
            abort_unless($request->user(), 403);
            $path = app(CareerWorkflow::class)->profile()->photo_path;
        }
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
