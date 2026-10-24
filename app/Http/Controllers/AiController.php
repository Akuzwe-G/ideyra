<?php

namespace App\Http\Controllers;

use App\Http\Requests\AiMessageRequest;
use App\Models\AiConversation;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\CvDocument;
use App\Models\Institution;
use App\Models\ProfessionalEntry;
use App\Models\ProjectShowcase;
use App\Support\AiContext;
use App\Support\AiWorkflow;
use App\Support\Audit;
use App\Support\CareerAccess;
use App\Support\CareerWorkflow;
use App\Support\DashboardAnalytics;
use App\Support\InnovationAccess;
use App\Support\InstitutionAccess;
use App\Support\OpportunityAccess;
use App\Support\ProjectWorkWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiController extends Controller
{
    public static function administer(?Institution $institution): bool
    {
        if (! $institution) {
            return InstitutionAccess::system();
        }
        $member = InstitutionAccess::membership($institution);

        return $member?->role->name === 'Institution Administrator' && in_array('institution.profile', InstitutionAccess::effective($member), true);
    }

    public function index(Request $request, ?AiConversation $conversation = null): View
    {
        if ($conversation) {
            Gate::authorize('view', $conversation);
            app(AiContext::class)->recheck($conversation, $request->user());
        }
        $data = $request->validate(['feature' => ['nullable', Rule::in(array_keys(config('ai.features')))], 'related_id' => 'nullable|integer|min:1', 'institution_id' => 'nullable|integer|min:1']);
        $feature = $conversation?->feature ?? ($data['feature'] ?? 'career');
        $relatedId = $conversation ? $conversation->related_id : ($data['related_id'] ?? null);
        $institutionId = $conversation ? $conversation->institution_id : ($data['institution_id'] ?? null);
        $relatedId = filled($relatedId) ? (int) $relatedId : null;
        $institutionId = filled($institutionId) ? (int) $institutionId : null;
        $record = app(AiContext::class)->record($request->user(), $feature, $relatedId);
        if ($record && array_key_exists('institution_id', $record->getAttributes())) {
            $institutionId = $record->institution_id;
        }
        $institution = $institutionId ? Institution::findOrFail($institutionId) : null;
        if ($institution) {
            abort_unless(InstitutionAccess::membership($institution), 403);
        }
        $conversations = AiConversation::where('user_id', $request->user()->id)->where('feature', $feature)->where('related_id', $relatedId)->where('institution_id', $institutionId)->where('expires_at', '>', now())->latest('updated_at')->limit(25)->get();
        $fields = app(AiContext::class)->fields($feature);
        $matchCandidates = $feature === 'opportunity' ? OpportunityAccess::visible($request->user(), false)->limit(30)->get() : collect();
        $tailoringCandidates = $feature === 'cv' ? OpportunityAccess::visible($request->user(), false)->where(fn ($q) => $q->whereNull('institution_id')->orWhere('institution_id', $institutionId))->limit(30)->get()->filter(fn ($o) => $o->isOpen()) : collect();
        $entries = ProfessionalEntry::whereHas('profile', fn ($q) => $q->where('user_id', $request->user()->id))->get();
        $aiShowcases = ProjectShowcase::whereHas('profile', fn ($q) => $q->where('user_id', $request->user()->id))->get()->filter(fn ($s) => CareerAccess::publicProject($s));
        $memberships = InnovationAccess::memberships($request->user());
        $enabled = app(AiWorkflow::class)->enabled($feature, $institutionId);
        $today = AiUsage::where('user_id', $request->user()->id)->where('created_at', '>=', today())->count();

        return view('ai.coach', compact('conversation', 'conversations', 'feature', 'relatedId', 'institutionId', 'institution', 'record', 'fields', 'entries', 'aiShowcases', 'matchCandidates', 'tailoringCandidates', 'memberships', 'enabled', 'today'));
    }

    public function send(AiMessageRequest $request, ?AiConversation $conversation = null): RedirectResponse|JsonResponse
    {
        if ($conversation) {
            Gate::authorize('update', $conversation);
            abort_if(count($conversation->messages ?? []) >= 40, 422, 'Start a new conversation to keep this session focused.');
        }
        try {
            $conversation = app(AiWorkflow::class)->send($request->user(), $request->validated(), $conversation);
        } catch (ValidationException $error) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => $error->errors()], 422);
            }
            $target = $conversation ? route('ai.conversation', $conversation) : route('ai.index', [
                'feature' => $request->validated('feature'),
                'related_id' => $request->validated('related_id'),
                'institution_id' => $request->validated('institution_id'),
            ]);

            return redirect()->to($target)->withErrors($error->validator);
        }

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('ai.conversation', $conversation)]);
        }

        return redirect()->route('ai.conversation', $conversation);
    }

    public function delete(AiConversation $conversation): RedirectResponse
    {
        Gate::authorize('delete', $conversation);
        $params = ['feature' => $conversation->feature, 'related_id' => $conversation->related_id, 'institution_id' => $conversation->institution_id];
        Audit::record('ai.conversation_deleted', $conversation, $conversation->institution_id);
        $conversation->delete();

        return redirect()->route('ai.index', $params)->with('status', 'Private conversation and saved insights deleted.');
    }

    public function apply(Request $request, AiConversation $conversation): RedirectResponse
    {
        Gate::authorize('update', $conversation);
        app(AiContext::class)->recheck($conversation, $request->user());
        $data = $request->validate(['message_index' => 'required|integer|min:0', 'action' => 'required|in:insight,idea,task,milestone,cv,feedback', 'text' => 'nullable|string|max:4000', 'title' => 'nullable|string|max:180', 'due_at' => 'nullable|date_format:Y-m-d', 'field' => ['nullable', Rule::in(array_keys(config('career.sections')))], 'confirm' => 'accepted']);
        DB::transaction(function () use ($conversation, $data, $request): void {
            $c = AiConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $message = $c->messages[$data['message_index']] ?? null;
            abort_unless(($message['role'] ?? '') === 'assistant', 422);
            $insights = $c->insights ?? [];
            $key = $data['message_index'].':'.$data['action'];
            abort_if(isset($insights[$key]), 422, 'This action was already saved.');
            $content = $message['content'];
            $record = app(AiContext::class)->record($request->user(), $c->feature, $c->related_id);
            $text = $data['text'] ?? $content['next_action'];
            if ($data['action'] === 'idea') {
                abort_unless($c->feature === 'idea', 422);
                Gate::authorize('update', $record);
                abort_unless(in_array($record->status, ['draft', 'developing', 'returned'], true), 422);
                $description = trim($record->description."\n\nReviewed AI insight: ".$text);
                abort_if(mb_strlen($description) > 12000, 422, 'The idea description would exceed its limit. Save a private insight instead.');
                $record->update(['description' => $description]);
            } elseif (in_array($data['action'], ['task', 'milestone'], true)) {
                abort_unless($c->feature === 'project' && filled($data['title'] ?? null) && filled($data['due_at'] ?? null), 422, 'A title and due date are required.');
                $workflow = app(ProjectWorkWorkflow::class);
                if ($data['action'] === 'task') {
                    $workflow->task($record, ['title' => $data['title'], 'description' => $text, 'due_at' => $data['due_at'], 'status' => 'to_do', 'priority' => 'medium', 'assignee_ids' => []]);
                } else {
                    $workflow->manage($record, 'milestones.create');
                    $workflow->milestone($record, ['title' => $data['title'], 'description' => $text, 'due_at' => $data['due_at'], 'status' => 'not_started', 'sort_order' => 0]);
                }
            } elseif ($data['action'] === 'cv') {
                abort_unless($c->feature === 'cv' && filled($data['field'] ?? null), 422);
                $record = CvDocument::whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless(($record->content[$data['field']] ?? '') === $content['original'], 422, 'The original CV has changed. Request a fresh suggestion.');
                $updated = $record->content;
                $updated[$data['field']] = $data['text'] ?? $content['revision'];
                app(CareerWorkflow::class)->saveCv(['title' => $record->title, 'template' => $record->template, 'sections' => $record->sections, 'content' => $updated], $record);
            } elseif ($data['action'] === 'feedback') {
                abort_unless($c->feature === 'supervision', 422);
                app(ProjectWorkWorkflow::class)->note($record, ['body' => $text, 'visibility' => 'feedback']);
            }
            $insights[$key] = ['text' => $text, 'action' => $data['action'], 'at' => now()->toIso8601String()];
            $c->update(['insights' => $insights]);
            Audit::record('ai.suggestion_reviewed', $c, $c->institution_id, ['action' => $data['action']]);
        });

        return redirect()->route('ai.conversation', $conversation)->with('status', 'Your reviewed choice has been saved.');
    }

    public function management(Request $request, ?Institution $institution = null): View|RedirectResponse
    {
        abort_unless(self::administer($institution), 403);
        if ($request->isMethod('post')) {
            $data = $request->validate(['enabled' => 'required|boolean', 'features' => 'nullable|array', 'features.*' => [Rule::in(array_keys(config('ai.features')))], 'daily_limit' => 'required|integer|min:0|max:100000', 'institutions' => 'nullable|array', 'institutions.*' => 'boolean', 'quotas' => 'nullable|array', 'quotas.*' => 'integer|min:0|max:100000']);
            if ($institution) {
                unset($data['institutions'],$data['quotas']);
                $data['features'] = array_values(array_intersect($data['features'] ?? [], AiSetting::values()['features'] ?? array_keys(config('ai.features'))));
                $data['daily_limit'] = min($data['daily_limit'], AiSetting::values()['quotas'][$institution->id] ?? config('ai.institution_daily'));
            }
            $data['features'] ??= [];
            $setting = AiSetting::updateOrCreate(['scope' => $institution ? 'institution:'.$institution->id : 'platform'], ['institution_id' => $institution?->id, 'settings' => $data]);
            Audit::record('ai.settings_updated', $setting, $institution?->id);

            return redirect()->to($institution ? route('ai.institution', $institution) : route('ai.management'))->with('status', 'AI controls updated.');
        }
        $settings = AiSetting::values($institution?->id);
        $platform = AiSetting::values();
        $institutions = $institution ? collect() : Institution::orderBy('name')->get();
        $available = app(AiWorkflow::class)->provider->available();

        return view('ai.management', compact('institution', 'settings', 'platform', 'institutions', 'available'));
    }

    public function activity(Request $request, ?string $chart = null): View|StreamedResponse
    {
        $data = $request->validate(['scope' => 'nullable|in:personal,system,institution', 'institution_id' => 'nullable|integer|min:1']);
        $scope = $data['scope'] ?? 'personal';
        $institution = $scope === 'institution' ? Institution::findOrFail($data['institution_id'] ?? 0) : null;
        if ($scope !== 'personal') {
            abort_unless(self::administer($institution), 403);
        }
        $filters = app(DashboardAnalytics::class)->filters($request);
        $q = AiUsage::query()->where('ai_usages.created_at', '>=', $filters['from'].' 00:00:00')->where('ai_usages.created_at', '<', CarbonImmutable::parse($filters['to'])->addDay());
        if ($scope === 'personal') {
            $q->where('user_id', $request->user()->id);
        } elseif ($institution) {
            $q->where('institution_id', $institution->id);
        }
        $chartData = function (string $key, string $title, string $type, array $labels, array $values, string $note): array {
            return ['key' => $key, 'title' => $title, 'type' => $type, 'labels' => $labels, 'values' => array_map('intval', $values), 'note' => $note, 'unit' => 'Requests'];
        };
        $features = (clone $q)->selectRaw('feature, COUNT(*) as total')->groupBy('feature')->pluck('total', 'feature');
        $status = (clone $q)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $bucket = fn ($date) => match ($filters['group']) {
            'daily' => $date->startOfDay(),'weekly' => $date->startOfWeek(),default => $date->startOfMonth()
        };
        $timeline = [];
        $end = $bucket(CarbonImmutable::parse($filters['to']));
        for ($cursor = $bucket(CarbonImmutable::parse($filters['from'])); $cursor->lte($end); $cursor = match ($filters['group']) {
            'daily' => $cursor->addDay(),'weekly' => $cursor->addWeek(),default => $cursor->addMonth()
        }) {
            $timeline[$cursor->toDateString()] = 0;
        }
        foreach ((clone $q)->selectRaw('DATE(created_at) as day,COUNT(*) as total')->groupByRaw('DATE(created_at)')->get() as $row) {
            $timeline[$bucket(CarbonImmutable::parse($row->day))->toDateString()] += $row->total;
        }
        $charts = [
            $chartData('timeline', 'AI usage over time', 'line', array_keys($timeline), array_values($timeline), 'Requests made in this period; failed attempts count toward quotas.'),
            $chartData('features', 'Requests by feature', 'bar', $features->keys()->map(fn ($key) => config('ai.features.'.$key, $key))->all(), $features->values()->all(), 'Activity is not a measure of intelligence or academic success.'),
            $chartData('status', 'Request outcomes', 'doughnut', $status->keys()->all(), $status->values()->all(), 'Pending requests may represent interrupted provider calls.'),
        ];
        if ($scope === 'system') {
            $counts = (clone $q)->leftJoin('institutions', 'institutions.id', '=', 'ai_usages.institution_id')->selectRaw("COALESCE(institutions.name, 'Personal workspace') as label,COUNT(*) as total")->groupBy('ai_usages.institution_id', 'institutions.name')->orderByDesc('total')->get();
            $charts[] = $chartData('institutions', 'Usage by institution', 'bar', $counts->pluck('label')->all(), $counts->pluck('total')->all(), 'Metadata only. Private conversation content is never included.');
        }
        $tokens = (int) (clone $q)->sum('input_tokens') + (int) (clone $q)->sum('output_tokens');
        $requests = (clone $q)->count();
        $failures = (clone $q)->whereNotIn('status', ['success', 'pending'])->count();
        $enabledFeatures = collect(array_keys(config('ai.features')))->filter(fn ($feature) => app(AiWorkflow::class)->enabled($feature, $institution?->id))->count();
        $analytics = ['kpis' => ['Requests' => $requests, 'Active AI users' => (clone $q)->distinct()->count('user_id'), 'Institutions using AI' => (clone $q)->distinct()->count('institution_id'), 'Reported tokens' => $tokens, 'Failed requests' => $failures, 'Failure rate (%)' => $requests ? (int) round($failures / $requests * 100) : 0, 'Enabled features' => $enabledFeatures], 'charts' => $charts];
        if ($scope === 'personal') {
            $analytics['kpis'] = ['Requests' => $requests, 'Reported tokens' => $tokens, 'Failed requests' => $failures];
            foreach (['idea' => 'Idea coaching sessions', 'project' => 'Project mentoring sessions', 'career' => 'Career exploration sessions', 'cv' => 'CV improvement sessions'] as $feature => $label) {
                $analytics['kpis'][$label] = (clone $q)->where('feature', $feature)->distinct()->count('ai_conversation_id');
            }
        }
        if ($chart) {
            $selected = collect($charts)->firstWhere('key', $chart);
            abort_unless($selected, 404);

            return response()->streamDownload(function () use ($selected): void {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Category', 'Requests'], ',', '"', '');
                foreach ($selected['labels'] as $i => $label) {
                    fputcsv($file, [preg_match('/^[\s=+\-@]/u', $label) ? "'".$label : $label, $selected['values'][$i]], ',', '"', '');
                }fclose($file);
            }, 'ideyra-ai-'.$chart.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }
        $daily = AiUsage::where('created_at', '>=', today());
        if ($institution) {
            $daily->where('institution_id', $institution->id);
            $limit = min(config('ai.institution_daily'), AiSetting::values()['quotas'][$institution->id] ?? PHP_INT_MAX, AiSetting::values($institution->id)['daily_limit'] ?? PHP_INT_MAX);
        } elseif ($scope === 'personal') {
            $daily->where('user_id', $request->user()->id);
            $limit = config('ai.user_daily');
        } else {
            $limit = min(config('ai.platform_daily'), AiSetting::values()['daily_limit'] ?? PHP_INT_MAX);
        }
        $used = $daily->count();

        return view('ai.activity', compact('analytics', 'filters', 'institution', 'scope', 'used', 'limit'));
    }
}
