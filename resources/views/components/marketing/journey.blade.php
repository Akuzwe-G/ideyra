<section class="story-section section" data-story>
<div class="container"><x-marketing.heading eyebrow="THE IDEYRA JOURNEY" title="One spark. A world of possibility.">Imagine → Build → Collaborate → Discover → Achieve. Follow the connected journey we’re designing, one deliberate step at a time.</x-marketing.heading>
<div class="story-layout"><div class="story-companion"><p class="concept-label">Illustrative journey · all workspace tools planned</p>
<div class="story-scene" aria-hidden="true"><div class="story-connection"></div>
<div class="scene-node" data-phase="0"><span class="scene-label">01 / IMAGINE</span><strong>What if campus felt more connected?</strong><small>A first question, kept safe.</small></div>
<div class="scene-node" data-phase="1"><span class="scene-label">02 / BUILD</span><strong>Campus connections</strong><small>Idea → direction → first milestone</small><div class="mini-progress"><span></span></div></div>
<div class="scene-node" data-phase="2"><span class="scene-label">03 / COLLABORATE</span><strong>Different strengths. Shared purpose.</strong><div class="sample-team"><span>You</span><span>Partner</span><span>Guide</span></div></div>
<div class="scene-node" data-phase="3"><span class="scene-label">04 / DISCOVER</span><strong>A possibility worth pursuing.</strong><small>Hackathon / Internship / Scholarship</small></div>
<div class="scene-node" data-phase="4"><span class="scene-label">05 / ACHIEVE</span><strong>Your work. A story to share.</strong><small>Project + skills + reflection → portfolio</small></div>
</div>
<nav class="story-nav" aria-label="Journey steps">@foreach(['Imagine', 'Build', 'Collaborate', 'Discover', 'Achieve'] as $name)<a href="#journey-{{ $loop->index }}" data-story-link="{{ $loop->index }}">{{ $name }}</a>@endforeach</nav></div>
<ol class="story-chapters">
@foreach([
['Imagine', 'A question can be the beginning.', 'Capture a spark before it disappears. The planned Idea Vault gives your own thinking a private place to develop.', 'A question → an idea'],
['Build', 'Give possibility a little structure.', 'Move from a thought to a direction. Project Studio is planned to connect your idea to milestones, tasks and meaningful outcomes.', 'An idea → a project'],
['Collaborate', 'Bring different strengths together.', 'Invite explicit collaborators, clarify responsibilities and move with a shared purpose. Personal work stays private until you choose to share it.', 'A project → a shared effort'],
['Discover', 'Find the next door to open.', 'Planned opportunity discovery and tracking will bring hackathons, internships, scholarships and competitions closer to the work you are doing.', 'Your work → a new possibility'],
['Achieve', 'Make your growth visible.', 'Choose the achievements and skills you want to show. Future CV and portfolio tools will help you tell the story of what you have built.', 'Experience → a professional story']
] as [$name, $title, $copy, $outcome])
<li id="journey-{{ $loop->index }}" class="story-chapter" data-story-step="{{ $loop->index }}"><div class="chapter-marker"><span>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><p class="eyebrow">{{ $name }}</p></div><h3>{{ $title }}</h3><p>{{ $copy }}</p><span class="chapter-outcome">{{ $outcome }}</span></li>
@endforeach
</ol></div></div></section>
